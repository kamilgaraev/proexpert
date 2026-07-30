<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\SavedViews;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAccessService;
use App\BusinessModules\Core\Reporting\Application\Input\ReportFilterNormalizer;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\CreateReportSavedViewData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedView;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewWindow;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\DTO\UpdateReportSavedViewData;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Infrastructure\Cursors\SignedReportSavedViewCursorCodec;
use InvalidArgumentException;

final readonly class ReportSavedViewService
{
    public function __construct(private ReportSavedViewStore $store, private ReportDefinitionRegistry $definitions, private ReportAccessService $access, private ReportFilterNormalizer $filters, private SignedReportSavedViewCursorCodec $cursors) {}

    public function list(ReportExecutionContext $context, ReportSavedViewWindow $window): ReportSavedViewPage
    {
        $cursor = $window->cursor === null ? null : $this->cursors->decode($window->cursor, $context->scope->organizationId, $context->actor->id, $window->reportCode);
        $page = $this->store->list($context->scope->organizationId, $context->actor->id, new ReportSavedViewWindow($cursor, $window->limit, $window->reportCode));
        foreach ($page->items as $v) {
            $this->view($context, $v->reportCode);
        }if (! $page->hasMore) {
            return $page;
        }$last = $page->items[array_key_last($page->items)];

        return new ReportSavedViewPage($page->items, $this->cursors->encode($context->scope->organizationId, $context->actor->id, $last->createdAt, $last->id, $window->reportCode), $page->limit, true);
    }

    public function get(ReportExecutionContext $c, string $id): ReportSavedView
    {
        $v = $this->store->getVisible($c->scope->organizationId, $c->actor->id, $id);
        $this->view($c, $v->reportCode);

        return $v;
    }

    public function create(ReportExecutionContext $c, array $input): ReportSavedView
    {
        $code = (string) ($input['report_code'] ?? '');
        $d = $this->definition($code);
        $this->manage($c, $code);
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('report_saved_view_name_invalid');
        }$columns = $d->validatedSelectedColumnIds($input['columns'] ?? []);
        $sort = $this->sort($d, $input['sort'] ?? []);
        $filters = $this->filters->normalize($c, $d, is_array($input['filters'] ?? null) ? $input['filters'] : []);
        $comparison = is_array($input['comparison'] ?? null) ? $input['comparison'] : [];

        return $this->store->create($c->scope->organizationId, $c->actor->id, new CreateReportSavedViewData($code, $name, (string) ($input['visibility'] ?? 'private'), $filters, $comparison, $sort, $columns, (bool) ($input['is_default'] ?? false)), $d->contractVersion);
    }

    public function update(ReportExecutionContext $c, string $id, array $input): ReportSavedView
    {
        $v = $this->get($c, $id);
        $d = $this->definition($v->reportCode);
        $this->manage($c, $v->reportCode);
        $changes = [];
        foreach (['name', 'visibility', 'comparison'] as $key) {
            if (array_key_exists($key, $input)) {
                $changes[$key] = $key === 'name' ? trim((string) $input[$key]) : $input[$key];
            }
        }if (array_key_exists('filters', $input)) {
            $changes['filters'] = $this->filters->normalize($c, $d, $input['filters']);
        }if (array_key_exists('columns', $input)) {
            $changes['columns'] = $d->validatedSelectedColumnIds($input['columns']);
        }if (array_key_exists('sort', $input)) {
            $changes['sort'] = $this->sort($d, $input['sort']);
        }if ($changes === []) {
            throw new InvalidArgumentException('report_saved_view_changes_invalid');
        }

return $this->store->updateLocked($c->scope->organizationId, $c->actor->id, $id, new UpdateReportSavedViewData($changes));
    }

    public function delete(ReportExecutionContext $c, string $id): void
    {
        $v = $this->get($c, $id);
        $this->manage($c, $v->reportCode);
        $this->store->softDeleteLocked($c->scope->organizationId, $c->actor->id, $id);
    }

    public function setDefault(ReportExecutionContext $c, string $id): ReportSavedView
    {
        $v = $this->get($c, $id);
        $this->manage($c, $v->reportCode);

        return $this->store->setDefaultLocked($c->scope->organizationId, $c->actor->id, $id);
    }

    private function definition(string $code): \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition
    {
        return $this->definitions->published($code)->payload();
    }

    private function view(ReportExecutionContext $c, string $code): void
    {
        $this->access->assertOperation($c, $this->definition($code), ReportOperation::VIEW, null);
    }

    private function manage(ReportExecutionContext $c, string $code): void
    {
        $this->access->assertOperation($c, $this->definition($code), ReportOperation::MANAGE, null);
    }

    private function sort(\App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition $d, array $p): ReportWindowSort
    {
        $field = (string) ($p['field'] ?? '');
        $direction = ReportSortDirection::tryFrom((string) ($p['direction'] ?? ''));
        if ($direction === null || ! in_array($field,array_column($d->sorts,'id'),true)) {
            throw new InvalidArgumentException('report_saved_view_sort_invalid');
        }

return new ReportWindowSort($field,$direction);
    }
}
