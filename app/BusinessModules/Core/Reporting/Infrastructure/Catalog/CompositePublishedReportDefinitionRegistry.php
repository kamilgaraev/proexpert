<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use LogicException;

final readonly class CompositePublishedReportDefinitionRegistry implements ReportDefinitionRegistry
{
    public function __construct(private ReportDefinitionRegistry $builtins, private ReportDefinitionRegistry $database) {}

    public function published(string $code): PublishedReportDefinition
    {
        try {
            $builtin = $this->builtins->published($code);
        } catch (ReportContractException $exception) {
            if ($exception->errorCode !== ReportErrorCode::REPORT_NOT_FOUND) {
                throw $exception;
            }

            return $this->database->published($code);
        }

        try {
            $this->database->published($code);
        } catch (ReportContractException $exception) {
            if ($exception->errorCode === ReportErrorCode::REPORT_NOT_FOUND) {
                return $builtin;
            }

            throw $exception;
        }

        throw new LogicException('report_published_definition_conflict');
    }

    public function publishedCodes(): array
    {
        $builtinCodes = $this->builtins->publishedCodes();
        $databaseCodes = $this->database->publishedCodes();
        if (array_intersect($builtinCodes, $databaseCodes) !== []) {
            throw new LogicException('report_published_definition_conflict');
        }

        return [...$builtinCodes, ...$databaseCodes];
    }

    public function manifestSha256(): Sha256Hash
    {
        $entries = [];
        foreach ($this->publishedCodes() as $code) {
            $published = $this->published($code);
            $entries[] = ['code' => $code, 'definition_sha256' => $published->definitionHash->value];
        }

        return new Sha256Hash(hash('sha256', CanonicalJson::encode($entries)));
    }
}
