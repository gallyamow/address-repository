<?php

declare(strict_types=1);

namespace Addresser\AddressRepository\Fias;

use Addresser\AddressRepository\ActualityComparator;
use Addresser\AddressRepository\Address;
use Addresser\AddressRepository\AddressBuilderInterface;
use Addresser\AddressRepository\AddressLevel;
use Addresser\AddressRepository\AddressSynonymizer;
use Addresser\AddressRepository\Exceptions\AddressBuildFailedException;
use Addresser\AddressRepository\AddressLevelSpec;
use Addresser\AddressRepository\Exceptions\RuntimeException;

/**
 * Формирует адрес на основе данных из ФИАС.
 * Работает только со структурой которую возвращает Finder.
 */
class FiasAddressBuilder implements AddressBuilderInterface
{
    private AddressLevelSpecResolverInterface $addrObjectTypeNameResolver;
    private AddressLevelSpecResolverInterface $houseTypeNameResolver;
    private AddressLevelSpecResolverInterface $addHouseTypeNameResolver;
    private AddressLevelSpecResolverInterface $apartmentTypeNameResolver;
    private AddressLevelSpecResolverInterface $roomTypeNameResolver;
    private ActualityComparator $actualityPeriodComparator;
    private AddressSynonymizer $addressSynonymizer;

    public function __construct(
        AddressLevelSpecResolverInterface $addrObjectTypeNameResolver,
        AddressLevelSpecResolverInterface $houseTypeNameResolver,
        AddressLevelSpecResolverInterface $addHouseTypeNameResolver,
        AddressLevelSpecResolverInterface $apartmentTypeNameResolver,
        AddressLevelSpecResolverInterface $roomTypeNameResolver,
        ActualityComparator $actualityPeriodComparator,
        AddressSynonymizer $addressSynonymizer
    ) {
        $this->addrObjectTypeNameResolver = $addrObjectTypeNameResolver;
        $this->houseTypeNameResolver = $houseTypeNameResolver;
        $this->addHouseTypeNameResolver = $addHouseTypeNameResolver;
        $this->apartmentTypeNameResolver = $apartmentTypeNameResolver;
        $this->roomTypeNameResolver = $roomTypeNameResolver;
        $this->actualityPeriodComparator = $actualityPeriodComparator;
        $this->addressSynonymizer = $addressSynonymizer;
    }

    // todo: refactor a huge loop
    public function build(array $data, ?Address $existsAddress = null): Address
    {
        $hierarchyId = (int)$data['hierarchy_id'];
        $objectId = (int)$data['object_id'];

        $parents = json_decode($data['parents'], true, 512, JSON_THROW_ON_ERROR);

        /**
         * группируем по уровням ФИАС, так как дополнительные локаций таких как СНТ, ГСК mapped на один и тот же
         * уровень \Addresser\AddressRepository\AddressLevel::SETTLEMENT - может быть несколько актуальных значений.
         */
        $parentsByLevels = [];
        foreach ($parents as $k => $item) {
            $fiasLevel = $this->resolveFiasLevel($item['relation']);

            $parentsByLevels[$fiasLevel] = $parentsByLevels[$fiasLevel] ?? [];
            $parentsByLevels[$fiasLevel][] = $item;
        }

        // мы должны сохранить изменения внесенные другими builder
        $address = $existsAddress ?? new Address();

        foreach ($parentsByLevels as $fiasLevel => $levelItems) {
            // находим актуальное значение
            $actualItem = array_values(
                array_filter(
                    $levelItems,
                    static function ($item) {
                        return $item['relation']['relation_is_active'] && $item['relation']['relation_is_actual'];
                    }
                )
            );

            /**
             * Здесь мы должны выделить доп. территории и заполнить ими поля.
             */
            if (count($actualItem) > 1) {
                throw AddressBuildFailedException::withIdentifier(
                    'object_id',
                    $objectId,
                    sprintf('There are "%d" actual relations for one fias level "%d"', count($actualItem), $fiasLevel),
                );
            }

            $actualItem = $actualItem[0];
            $addressLevel = FiasLevel::mapAdmHierarchyToAddressLevel($fiasLevel);
            $relationData = $actualItem['relation']['relation_data'];

            $actualParams = $this->resolveActualParams(
                $actualItem['params'] ?? [],
                [FiasParamType::KLADR, FiasParamType::OKATO, FiasParamType::OKTMO, FiasParamType::POSTAL_CODE]
            );

            $kladrId = $actualParams[FiasParamType::KLADR]['value'] ?? null;
            $okato = $actualParams[FiasParamType::OKATO]['value'] ?? null;
            $oktmo = $actualParams[FiasParamType::OKTMO]['value'] ?? null;
            $postalCode = $actualParams[FiasParamType::POSTAL_CODE]['value'] ?? null;

            $fiasId = null;
            switch ($addressLevel) {
                case AddressLevel::REGION:
                    $fiasId = $relationData['objectguid'];
                    if (empty($fiasId)) {
                        throw AddressBuildFailedException::withIdentifier(
                            'object_id',
                            $objectId,
                            sprintf('Empty fiasId for region level.'),
                        );
                    }

                    $name = $relationData['name'];
                    if (empty($name)) {
                        throw AddressBuildFailedException::withIdentifier(
                            'object_id',
                            $objectId,
                            sprintf('Empty name for region level.'),
                        );
                    }

                    $address->setRegionFiasId($fiasId);
                    $address->setRegionKladrId($kladrId);

                    $typeName = $this->resolveLevelSpec($addressLevel, $relationData);
                    $address->setRegionType($typeName->getShortName());
                    $address->setRegionTypeFull($typeName->getName());

                    $address->setRegion($this->prepareString($name));
                    // учитываем переименование регионов
                    $address->setRenaming($this->resolveLevelRenaming($levelItems, $name));
                    break;
                case AddressLevel::AREA:
                    $fiasId = $relationData['objectguid'];
                    $name = $relationData['name'];

                    $address->setAreaFiasId($fiasId);
                    $address->setAreaKladrId($kladrId);

                    $typeName = $this->resolveLevelSpec($addressLevel, $relationData);
                    $address->setAreaType($typeName->getShortName());
                    $address->setAreaTypeFull($typeName->getName());

                    $address->setArea($this->prepareString($name));
                    // учитываем переименование районов
                    $address->setRenaming($this->resolveLevelRenaming($levelItems, $name));
                    break;
                case AddressLevel::CITY:
                    $fiasId = $relationData['objectguid'];
                    $name = $relationData['name'];

                    $address->setCityFiasId($fiasId);
                    $address->setCityKladrId($kladrId);

                    $typeName = $this->resolveLevelSpec($addressLevel, $relationData);
                    $address->setCityType($typeName->getShortName());
                    $address->setCityTypeFull($typeName->getName());

                    $address->setCity($this->prepareString($name));
                    // учитываем переименование городов
                    $address->setRenaming($this->resolveLevelRenaming($levelItems, $name));
                    break;
                case AddressLevel::SETTLEMENT:
                    $fiasId = $relationData['objectguid'];
                    $name = $relationData['name'];

                    $address->setSettlementFiasId($fiasId);
                    $address->setSettlementKladrId($kladrId);

                    $typeName = $this->resolveLevelSpec($addressLevel, $relationData);
                    $address->setSettlementType($typeName->getShortName());
                    $address->setSettlementTypeFull($typeName->getName());

                    $address->setSettlement($this->prepareString($name));
                    // учитываем переименование поселений
                    $address->setRenaming($this->resolveLevelRenaming($levelItems, $name));
                    break;
                case AddressLevel::STREET:
                    $fiasId = $relationData['objectguid'];
                    $name = $relationData['name'];

                    $address->setStreetFiasId($fiasId);
                    $address->setStreetKladrId($kladrId);

                    $typeName = $this->resolveLevelSpec($addressLevel, $relationData);
                    $address->setStreetType($typeName->getShortName());
                    $address->setStreetTypeFull($typeName->getName());

                    $address->setStreet($this->prepareString($name));
                    // учитываем переименование улиц
                    $address->setRenaming($this->resolveLevelRenaming($levelItems, $name));
                    break;
                case AddressLevel::HOUSE:
                    $fiasId = $relationData['objectguid'];
                    $address->setHouseFiasId($fiasId);
                    $address->setHouseKladrId($kladrId);

                    $typeName = $this->resolveLevelSpec($addressLevel, $relationData);
                    $address->setHouseType($typeName->getShortName());
                    $address->setHouseTypeFull($typeName->getName());

                    $address->setHouse($this->prepareString($relationData['housenum']));

                    $address->setBlock1($relationData['addnum1'] ?? null);
                    if ($relationData['addtype1']) {
                        $blockTypeName = $this->resolveHouseBlockSpec((int)$relationData['addtype1']);

                        $address->setBlockType1($blockTypeName->getShortName());
                        $address->setBlockTypeFull1($blockTypeName->getName());
                    }

                    $address->setBlock2($relationData['addnum2'] ?? null);
                    if ($relationData['addtype2']) {
                        $blockTypeName = $this->resolveHouseBlockSpec((int)$relationData['addtype2']);

                        $address->setBlockType2($blockTypeName->getShortName());
                        $address->setBlockTypeFull2($blockTypeName->getName());
                    }
                    break;
                case AddressLevel::FLAT:
                    $fiasId = $relationData['objectguid'];
                    $address->setFlatFiasId($fiasId);

                    $typeName = $this->resolveLevelSpec($addressLevel, $relationData);
                    $address->setFlatType($typeName->getShortName());
                    $address->setFlatTypeFull($typeName->getName());

                    $address->setFlat($this->prepareString($relationData['number']));
                    break;
                case AddressLevel::ROOM:
                    $fiasId = $relationData['objectguid'];
                    $address->setRoomFiasId($fiasId);

                    $typeName = $this->resolveLevelSpec($addressLevel, $relationData);
                    $address->setRoomType($typeName->getShortName());
                    $address->setRoomTypeFull($typeName->getName());

                    $address->setRoom($this->prepareString($relationData['number']));
                    break;
                case AddressLevel::STEAD:
                case AddressLevel::CAR_PLACE:
                    // эти уровни не индексируем, таким образом сюда они попадать не должны
                    throw new RuntimeException('Unsupported address level.');
            }

            // данные последнего уровня
            if ($addressLevel === \array_key_last($parentsByLevels)) {
                if (null === $fiasId) {
                    throw AddressBuildFailedException::withIdentifier(
                        'object_id',
                        $objectId,
                        sprintf('Empty fiasId for region level.'),
                    );
                }

                $address->setFiasId($fiasId);
                $address->setAddressLevel($addressLevel);
                $address->setFiasLevel($fiasLevel);
                $address->setFiasHierarchyId($hierarchyId);
                $address->setOkato($okato ?? null);
                $address->setOktmo($oktmo ?? null);
                $address->setPostalCode($postalCode ?? null);
                $address->setKladrId($kladrId ?? null);
                $address->setSynonyms($this->addressSynonymizer->getSynonyms($fiasId));
            }
        }

        return $address;
    }

    private function resolveLevelRenaming(array $levelItems, string $currentName, string $nameField = 'name'): array
    {
        $notActualItems = array_values(
            array_filter(
                $levelItems,
                static function ($item) {
                    return !($item['relation']['relation_is_active'] && $item['relation']['relation_is_actual']);
                }
            )
        );

        return array_values(
            array_filter(
                array_unique(
                    array_map(
                        static function ($item) use ($nameField) {
                            return $item['relation']['relation_data'][$nameField];
                        },
                        $notActualItems
                    )
                ),
                static function ($name) use ($currentName) {
                    return $name !== $currentName;
                }
            )
        );
    }

    private function resolveLevelSpec(int $addressLevel, array $relationData): AddressLevelSpec
    {
        $typeName = null;

        switch ($addressLevel) {
            case AddressLevel::REGION:
            case AddressLevel::AREA:
            case AddressLevel::CITY:
            case AddressLevel::SETTLEMENT:
            case AddressLevel::STREET:
                return $this->addrObjectTypeNameResolver->resolve($addressLevel, $relationData['typename']);
            case AddressLevel::HOUSE:
                // Респ Башкортостан, г Кумертау, ул Брикетная, влд 5 к А стр 1/6
                return $this->houseTypeNameResolver->resolve(AddressLevel::HOUSE, (int)$relationData['housetype']);
            case AddressLevel::FLAT:
                return $this->apartmentTypeNameResolver->resolve(
                    AddressLevel::FLAT,
                    (int)$relationData['aparttype']
                );
            case AddressLevel::ROOM:
                return $this->roomTypeNameResolver->resolve(AddressLevel::ROOM, (int)$relationData['roomtype']);
        }

        throw new AddressBuildFailedException(
            sprintf('AddressLevel %d has no type', $addressLevel)
        );
    }

    private function resolveHouseBlockSpec(int $addHouseType): AddressLevelSpec
    {
        return $this->addHouseTypeNameResolver->resolve(AddressLevel::HOUSE, $addHouseType);
    }

    private function resolveAddressLevel(array $relation): int
    {
        $relationType = $relation['relation_type'];

        switch ($relationType) {
            case FiasRelationType::ADDR_OBJ:
                $fiasLevel = (int)$relation['relation_data']['level'];

                return FiasLevel::mapAdmHierarchyToAddressLevel($fiasLevel);
            case FiasRelationType::HOUSE:
                return AddressLevel::HOUSE;
            case FiasRelationType::APARTMENT:
                return AddressLevel::FLAT;
            case FiasRelationType::ROOM:
                return AddressLevel::ROOM;
            case FiasRelationType::CAR_PLACE:
                return AddressLevel::CAR_PLACE;
            case FiasRelationType::STEAD:
                return AddressLevel::STEAD;
        }

        throw new RuntimeException(sprintf('Failed to resolve AddressLevel by relation_type "%s"', $relationType));
    }

    private function resolveFiasLevel(array $relation): int
    {
        $relationType = $relation['relation_type'];

        switch ($relationType) {
            case FiasRelationType::ADDR_OBJ:
                return (int)$relation['relation_data']['level'];
            case FiasRelationType::HOUSE:
                return FiasLevel::BUILDING;
            case FiasRelationType::APARTMENT:
                return FiasLevel::PREMISES;
            case FiasRelationType::ROOM:
                return FiasLevel::PREMISES_WITHIN_THE_PREMISES;
            case FiasRelationType::CAR_PLACE:
                return FiasLevel::CAR_PLACE;
            case FiasRelationType::STEAD:
                return FiasLevel::STEAD;
        }

        throw new RuntimeException(sprintf('Failed to resolve FiasLevel by relation_type "%s"', $relationType));
    }

    private function resolveActualParams(array $groupedHierarchyParams, array $keys): array
    {
        $res = [];
        $currentDate = date('Y-m-d');

        foreach ($groupedHierarchyParams as $hierarchyParam) {
            foreach ($hierarchyParam['values'] as $valueItem) {
                $typeId = $valueItem['type_id'];

                if (in_array($typeId, $keys, true)) {
                    // сразу пропускаем неактуальные
                    if ($valueItem['end_date'] < $currentDate) {
                        continue;
                    }

                    $oldValueItem = $res[$typeId] ?? null;

                    if (null === $oldValueItem
                        || ($oldValueItem && $this->actualityPeriodComparator->compare(
                                $oldValueItem['start_date'],
                                $oldValueItem['end_date'],
                                $valueItem['start_date'],
                                $valueItem['end_date']
                            ) === -1)
                    ) {
                        // обновляем только если новое значении более актуальное чем старое
                        $res[$typeId] = $valueItem;
                    }
                }
            }
        }

        return $res;
    }

    private function prepareString(?string $s): ?string
    {
        if (null === $s) {
            return null;
        }
        $tmp = trim($s);

        return empty($tmp) ? null : $tmp;
    }
}
