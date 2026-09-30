<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Documents;

use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantOperationsNativeFileAdapter;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantSalesNativeFileAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class NativeSourceFieldOrderTest extends TestCase
{
    #[DataProvider('adapters')]
    public function test_jsonb_object_order_does_not_change_identity_but_keys_values_and_types_do(string $adapter): void
    {
        $compare = new ReflectionMethod($adapter, 'sameNativeSourceFields');
        $fields = ['native_entity_type' => 'crm_import_batch', 'organization_id' => '7', 'id' => 'abc', 'optional' => null];
        $reordered = array_reverse($fields, true);
        self::assertTrue($compare->invoke(null, $fields, $reordered));
        self::assertSame(['optional', 'id', 'organization_id', 'native_entity_type'], array_keys($reordered));
        $missing = $fields;
        unset($missing['optional']);
        self::assertFalse($compare->invoke(null, $fields, $missing));
        self::assertFalse($compare->invoke(null, $fields, $fields + ['extra' => null]));
        self::assertFalse($compare->invoke(null, $fields, array_replace($fields, ['id' => 'other'])));
        self::assertFalse($compare->invoke(null, $fields, array_replace($fields, ['organization_id' => 7])));
        self::assertFalse($compare->invoke(null, $fields, array_replace($fields, ['optional' => ''])));
        self::assertFalse($compare->invoke(null, $fields, null));
        self::assertFalse($compare->invoke(null, null, null));
    }

    public static function adapters(): iterable
    {
        yield 'operations' => [AssistantOperationsNativeFileAdapter::class];
        yield 'sales' => [AssistantSalesNativeFileAdapter::class];
    }
}
