<?php

declare(strict_types=1);

namespace Addresser\AddressRepository\Tests\Fias\LevelNameResolvers;

use Addresser\AddressRepository\Exceptions\LevelNameNotFoundException;
use Addresser\AddressRepository\Fias\FiasLevelNameResolverInterface;
use Addresser\AddressRepository\Fias\LevelNameResolvers\FiasHouseLevelNameResolver;
use Addresser\AddressRepository\LevelName;
use PHPUnit\Framework\TestCase;

class FiasHouseLevelNameResolverTest extends TestCase
{
    private FiasLevelNameResolverInterface $resolver;

    protected function setUp(): void
    {
        $this->resolver = new FiasHouseLevelNameResolver();
    }

    /**
     * @test
     */
    public function itShouldThrowExceptionWhenCannotResolve(): void
    {
        $this->expectException(LevelNameNotFoundException::class);
        $this->assertEquals(
            new LevelName('литера', 'лит.'),
            $this->resolver->resolve(50000)
        );
    }

    /**
     * @test
     */
    public function itCorrectlyResolvesNormalizedTypes(): void
    {
        $this->assertEquals(
            new LevelName('литера', 'лит.'),
            $this->resolver->resolve(9)
        );
        $this->assertEquals(
            new LevelName('корпус', 'корп.'),
            $this->resolver->resolve(10)
        );
    }
}
