# Multi-Organization Workflow

## Canonical API Boundaries

- LK core workflow lives under `/api/v1/landing/multi-organization/*`.
- Public holding data lives under `/api/v1/holding-api/{slug}/*`.
- Holding site builder and public site operations live under `/api/v1/landing/holding/site/*` and `/api/v1/landing/holding/public/*`.
- Core LK workflow must not be duplicated in public or site-builder route contours.

## Organization Lifecycle

1. A regular organization starts as `organization_type=single`, `is_holding=false`.
2. Creating a holding converts the current organization to `organization_type=parent`, `is_holding=true`, `hierarchy_level=0`, `hierarchy_path=<parent_id>`.
3. The holding receives one `organization_groups` record with `parent_organization_id=<parent_id>`.
4. Adding a child creates `organization_type=child`, `is_holding=false`, `parent_organization_id=<parent_id>`, `hierarchy_level=1`, `hierarchy_path=<parent_path>.<child_id>`.
5. A child can transfer data only to the parent holding or to a sibling child inside the same holding.
6. A child cannot transfer data to itself or to an unrelated organization.

## Child Users and Roles

- Child organization users are attached through `organization_user`.
- Effective permissions are controlled by `user_role_assignments` in the child organization authorization context.
- Removing a user from a child organization must deactivate active assignments for that child context before removing the pivot link.
- System roles use `role_type=system`; custom organization roles use `role_type=custom`.

## Permissions

- Read routes require `multi-organization.view`.
- Dashboard routes require `multi-organization.dashboard`.
- Management routes require `multi-organization.manage`.
- Report routes require `multi-organization.reports.view` or a more specific report permission such as `multi-organization.reports.financial`.
- `check-availability` is the only LK multi-organization route that can exclude `module.access:multi-organization`; it must not expose sensitive organization data.

## Response Contracts

- LK multi-organization routes use `LandingResponse`.
- Admin response shapes are not used under `/api/v1/landing/multi-organization/*`.
- Paginated LK endpoints return top-level `success`, `message`, `data`, `meta` and optional `links`.

## Test Coverage

- `MultiOrganizationRouteInventoryTest` locks the canonical LK route inventory.
- `MultiOrganizationPermissionsTest` locks route-level permissions and LK response class usage.
- `MultiOrganizationWorkflowTest` locks create holding, add child, safe transfer, child user removal and role assignment behavior.
