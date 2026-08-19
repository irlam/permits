<?php
declare(strict_types=1);

use Permits\TemplateCatalog;
use PHPUnit\Framework\TestCase;

final class Phase3TemplateCatalogTest extends TestCase
{
    public function testPickerPrefersPhase3SpecialistV2TemplatesOverLegacyRows(): void
    {
        $templates = TemplateCatalog::latestByName([
            ['id' => 'blasting-explosives-v1', 'name' => 'Blasting/Explosives Permit', 'version' => 9],
            ['id' => 'blasting-explosives-v2', 'name' => 'Blasting/Explosives Permit', 'version' => 2],
            ['id' => 'restricted-area-entry-v1', 'name' => 'Restricted Area Entry Permit', 'version' => 9],
            ['id' => 'restricted-area-entry-v2', 'name' => 'Restricted Area Entry Permit', 'version' => 2],
            ['id' => 'vehicle-equipment-access-v1', 'name' => 'Vehicle/Equipment Access Permit', 'version' => 9],
            ['id' => 'vehicle-equipment-access-v2', 'name' => 'Vehicle/Equipment Access Permit', 'version' => 2],
            ['id' => 'concrete-pouring-v1', 'name' => 'Concrete Pouring Permit', 'version' => 9],
            ['id' => 'concrete-pouring-v2', 'name' => 'Concrete Pouring Permit', 'version' => 2],
        ]);

        $byName = [];
        foreach ($templates as $template) {
            $byName[$template['name']] = $template['id'];
        }

        self::assertSame('blasting-explosives-v2', $byName['Blasting/Explosives Permit']);
        self::assertSame('restricted-area-entry-v2', $byName['Restricted Area Entry Permit']);
        self::assertSame('vehicle-equipment-access-v2', $byName['Vehicle/Equipment Access Permit']);
        self::assertSame('concrete-pouring-v2', $byName['Concrete Pouring Permit']);
    }
}
