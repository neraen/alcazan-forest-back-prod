<?php

class SpellTest extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
{

    public function setUp(): void
    {
        // (1) boot the Symfony kernel
       self::bootKernel();

        // (2) use static::getContainer() to access the service container

    }

    public function testGetSpellById()
    {
        $spellService = self::$kernel->getContainer()->get(\App\service\SpellService::class);
        $result = $spellService->egal1();


        $this->assertEquals(1, $result);
    }
}