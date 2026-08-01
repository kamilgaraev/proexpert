<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement;

use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceEmployeeService;
use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceProService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class WorkforceOwnerTransactionBoundaryTest extends TestCase
{
    #[Test]
    #[DataProvider('tableSpecificOwnerFlows')]
    public function table_specific_owner_flow_locks_organization_inside_outer_transaction_before_mutation(
        string $method,
        string $mutationCall,
    ): void {
        $source = $this->methodSource(WorkforceProService::class, $method);

        $transaction = strpos($source, 'return DB::transaction(');
        $organizationLock = strpos($source, '$this->lockOrganization(');
        $mutation = strrpos($source, $mutationCall);

        self::assertNotFalse($transaction, "{$method} must own an outer transaction");
        self::assertNotFalse($organizationLock, "{$method} must lock its organization");
        self::assertNotFalse($mutation, "{$method} must invoke its mutation boundary");
        self::assertLessThan($organizationLock, $transaction);
        self::assertLessThan($mutation, $organizationLock);
    }

    public static function tableSpecificOwnerFlows(): array
    {
        return [
            'store staff unit' => ['storeStaffUnit', '$this->store('],
            'update staff unit' => ['updateStaffUnit', '$this->update('],
            'store or update assignment' => ['storeAssignment', '$this->update('],
            'store schedule day' => ['storeScheduleDay', '$this->store('],
            'store absence' => ['storeAbsence', '$this->store('],
            'store business trip' => ['storeBusinessTrip', '$this->store('],
            'approve absence' => ['approveAbsence', '$this->update('],
            'cancel absence' => ['cancelAbsence', '$this->update('],
            'approve business trip' => ['approveBusinessTrip', '$this->update('],
            'cancel business trip' => ['cancelBusinessTrip', '$this->update('],
        ];
    }

    #[Test]
    public function dismissal_refetches_and_validates_employee_after_organization_lock(): void
    {
        $source = $this->methodSource(WorkforceEmployeeService::class, 'dismiss');

        $transaction = strpos($source, 'return DB::transaction(');
        $organizationLock = strpos($source, '$this->lockOrganization(');
        $employeeRefetch = strpos($source, '$employee = $this->find(');
        $hireDateValidation = strpos($source, '$employee->hire_date');
        $captureBegin = strpos($source, '$this->capacityCapture->beginDismissal(');
        $employeeMutation = strpos($source, '$employee->update(');
        $captureFinish = strpos($source, '$this->capacityCapture->finishDismissal(');

        self::assertNotFalse($transaction);
        self::assertNotFalse($organizationLock);
        self::assertNotFalse($employeeRefetch);
        self::assertNotFalse($hireDateValidation);
        self::assertNotFalse($captureBegin);
        self::assertNotFalse($employeeMutation);
        self::assertNotFalse($captureFinish);
        self::assertLessThan($organizationLock, $transaction);
        self::assertLessThan($employeeRefetch, $organizationLock);
        self::assertLessThan($hireDateValidation, $employeeRefetch);
        self::assertLessThan($captureBegin, $hireDateValidation);
        self::assertLessThan($employeeMutation, $captureBegin);
        self::assertLessThan($captureFinish, $employeeMutation);
        self::assertStringNotContainsString('->get(', $source);
        self::assertStringNotContainsString('assignmentIds', $source);
        self::assertStringNotContainsString('updatedAssignments', $source);
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $file = $reflection->getFileName();
        self::assertIsString($file);
        $lines = file($file);
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
