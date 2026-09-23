# Mobile team expansion API

All routes require mobile authentication, organization context, mobile-app access, and the permission shown below. Lists use `{success, data: [...], meta: {current_page, last_page, per_page, total}}`; detail and create responses return the record under `data.item`. Page size is capped at 50.

| Method and path | Permission | Behavior |
| --- | --- | --- |
| `GET /api/v1/mobile/team-expansion/contractors` | `contractor_marketplace.search.view` | Search network contractors; supports `search`, `category_id`, `city`, capacity, budget, rating, availability, verification, sorting, and pagination filters. |
| `GET /api/v1/mobile/team-expansion/contractors/{profile}` | `contractor_marketplace.profile.view` | View a currently published contractor profile visible to the organization network. |
| `POST /api/v1/mobile/team-expansion/contractor-invitations` | `contractor_marketplace.offers.create` | Send a hiring offer using the marketplace offer payload, including project, contractor profile, role, title, and work packages. |
| `GET /api/v1/mobile/team-expansion/brigades` | `brigades.catalog.view` | Search approved brigade profiles; supports search, specialization, city, availability, and pagination. |
| `GET /api/v1/mobile/team-expansion/brigades/{brigade}` | `brigades.catalog.view` | View an approved brigade profile. |
| `GET /api/v1/mobile/team-expansion/brigade-requests` | `brigades.requests.view` | List requests created by the current organization; filter by project and status. |
| `POST /api/v1/mobile/team-expansion/brigade-requests` | `brigades.requests.create` | Create a brigade request. Optional project must belong to the current organization. |
| `GET /api/v1/mobile/team-expansion/brigade-requests/{brigadeRequest}/responses` | `brigades.responses.view` | List responses for a request owned by the current organization; filter by status. |
| `POST /api/v1/mobile/team-expansion/brigade-requests/{brigadeRequest}/responses/{response}/approve` | `brigades.responses.approve` | Approve a pending response for an open request and create its assignment in one transaction. |
| `GET /api/v1/mobile/team-expansion/brigade-invitations` | `brigades.invitations.view` | List invitations issued by the current organization; filter by project and status. |
| `POST /api/v1/mobile/team-expansion/brigade-invitations` | `brigades.invitations.create` | Invite an approved brigade to a project owned by the current organization. Duplicate pending invitations are rejected. |

Marketplace invitation bodies follow the existing `StoreMarketplaceHiringOfferRequest` contract. Brigade request bodies follow `title`, `description`, optional `project_id`, `specialization_name`, `city`, `team_size_min`, and `team_size_max`. Brigade invitation bodies use `brigade_id`, required `project_id`, and optional `message`, `starts_at`, and `expires_at`.
