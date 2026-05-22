# Mobile API Contract Checklist

Date: 2026-05-22

Source: `php artisan route:list --path=api/v1/mobile --json`.

Policy: mobile clients use only `/api/v1/mobile/*`, canonical routes only, MobileResponse envelope, translated business messages, and no compatibility aliases.

| Route | Module | Route exists | Auth required | Permission checked | MobileResponse used | Translated messages | Success example | Validation error example | Mobile parser test | Backend feature test |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `POST /ai-assistant/actions/execute` | AI Assistant | yes | yes | yes | yes | yes | yes | yes | test/features/ai_assistant | AIAssistantMobileTest.php |
| `POST /ai-assistant/actions/preview` | AI Assistant | yes | yes | yes | yes | yes | yes | yes | test/features/ai_assistant | AIAssistantMobileTest.php |
| `POST /ai-assistant/chat` | AI Assistant | yes | yes | yes | yes | yes | yes | yes | test/features/ai_assistant | AIAssistantMobileTest.php |
| `GET /ai-assistant/conversations` | AI Assistant | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/ai_assistant | AIAssistantMobileTest.php |
| `DELETE /ai-assistant/conversations/{conversation}` | AI Assistant | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/ai_assistant | AIAssistantMobileTest.php |
| `GET /ai-assistant/conversations/{conversation}` | AI Assistant | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/ai_assistant | AIAssistantMobileTest.php |
| `GET /ai-assistant/usage` | AI Assistant | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/ai_assistant | AIAssistantMobileTest.php |
| `POST /auth/login` | Auth | yes | no: login endpoint | n/a | yes | yes | yes | yes | test/features/auth | MobileApiContractDocumentationTest.php |
| `POST /auth/logout` | Auth | yes | yes | yes | yes | yes | yes | yes | test/features/auth | MobileApiContractDocumentationTest.php |
| `GET /auth/me` | Auth | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/auth | MobileApiContractDocumentationTest.php |
| `POST /auth/refresh` | Auth | yes | yes | yes | yes | yes | yes | yes | test/features/auth | MobileApiContractDocumentationTest.php |
| `POST /auth/switch-organization` | Auth | yes | yes | yes | yes | yes | yes | yes | test/features/auth | MobileApiContractDocumentationTest.php |
| `GET /budget-estimates/estimates` | Budget Estimates | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/budget_estimates | MobileApiContractDocumentationTest.php |
| `GET /budget-estimates/estimates/{estimate}` | Budget Estimates | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/budget_estimates | MobileApiContractDocumentationTest.php |
| `POST /budget-estimates/estimates/{estimate}/approve` | Budget Estimates | yes | yes | yes | yes | yes | yes | yes | test/features/budget_estimates | MobileApiContractDocumentationTest.php |
| `POST /budget-estimates/estimates/{estimate}/request-changes` | Budget Estimates | yes | yes | yes | yes | yes | yes | yes | test/features/budget_estimates | MobileApiContractDocumentationTest.php |
| `GET /budget-estimates/summary` | Budget Estimates | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/budget_estimates | MobileApiContractDocumentationTest.php |
| `GET /companions/{module}` | Companion Modules | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/module_companions | MobileCompanionModulesTest.php |
| `GET /companions/{module}/{id}` | Companion Modules | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/module_companions | MobileCompanionModulesTest.php |
| `POST /companions/{module}/{id}/actions/{action}` | Companion Modules | yes | yes | yes | yes | yes | yes | yes | test/features/module_companions | MobileCompanionModulesTest.php |
| `GET /construction-journals` | Construction Journal | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/construction_journal | MobileApiContractDocumentationTest.php |
| `POST /construction-journals` | Construction Journal | yes | yes | yes | yes | yes | yes | yes | test/features/construction_journal | MobileApiContractDocumentationTest.php |
| `GET /construction-journals/{journal}` | Construction Journal | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/construction_journal | MobileApiContractDocumentationTest.php |
| `PUT /construction-journals/{journal}` | Construction Journal | yes | yes | yes | yes | yes | yes | yes | test/features/construction_journal | MobileApiContractDocumentationTest.php |
| `GET /construction-journals/{journal}/entries` | Construction Journal | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/construction_journal | MobileApiContractDocumentationTest.php |
| `POST /construction-journals/{journal}/entries` | Construction Journal | yes | yes | yes | yes | yes | yes | yes | test/features/construction_journal | MobileApiContractDocumentationTest.php |
| `GET /construction-journals/{journal}/entry-form-options` | Construction Journal | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/construction_journal | MobileApiContractDocumentationTest.php |
| `POST /construction-journals/{journal}/export/extended` | Construction Journal | yes | yes | yes | yes | yes | yes | yes | test/features/construction_journal | MobileApiContractDocumentationTest.php |
| `POST /construction-journals/{journal}/export/ks6` | Construction Journal | yes | yes | yes | yes | yes | yes | yes | test/features/construction_journal | MobileApiContractDocumentationTest.php |
| `GET /dashboard` | Dashboard | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/dashboard | MobileDashboardTest.php |
| `POST /handover-acceptance/checklist-items/{item}/review` | Handover Acceptance | yes | yes | yes | yes | yes | yes | yes | test/features/handover_acceptance | HandoverAcceptanceMobileTest.php |
| `POST /handover-acceptance/findings/{finding}/resolve` | Handover Acceptance | yes | yes | yes | yes | yes | yes | yes | test/features/handover_acceptance | HandoverAcceptanceMobileTest.php |
| `POST /handover-acceptance/package-documents/{document}/upload` | Handover Acceptance | yes | yes | yes | yes | yes | yes | yes | test/features/handover_acceptance | HandoverAcceptanceMobileTest.php |
| `GET /handover-acceptance/scopes` | Handover Acceptance | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/handover_acceptance | HandoverAcceptanceMobileTest.php |
| `GET /handover-acceptance/scopes/{scope}` | Handover Acceptance | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/handover_acceptance | HandoverAcceptanceMobileTest.php |
| `POST /handover-acceptance/scopes/{scope}/accept` | Handover Acceptance | yes | yes | yes | yes | yes | yes | yes | test/features/handover_acceptance | HandoverAcceptanceMobileTest.php |
| `POST /handover-acceptance/scopes/{scope}/handover` | Handover Acceptance | yes | yes | yes | yes | yes | yes | yes | test/features/handover_acceptance | HandoverAcceptanceMobileTest.php |
| `POST /handover-acceptance/scopes/{scope}/ready-for-reinspection` | Handover Acceptance | yes | yes | yes | yes | yes | yes | yes | test/features/handover_acceptance | HandoverAcceptanceMobileTest.php |
| `POST /handover-acceptance/scopes/{scope}/reject` | Handover Acceptance | yes | yes | yes | yes | yes | yes | yes | test/features/handover_acceptance | HandoverAcceptanceMobileTest.php |
| `POST /handover-acceptance/scopes/{scope}/reopen` | Handover Acceptance | yes | yes | yes | yes | yes | yes | yes | test/features/handover_acceptance | HandoverAcceptanceMobileTest.php |
| `POST /handover-acceptance/scopes/{scope}/start` | Handover Acceptance | yes | yes | yes | yes | yes | yes | yes | test/features/handover_acceptance | HandoverAcceptanceMobileTest.php |
| `POST /handover-acceptance/sessions/{session}/findings` | Handover Acceptance | yes | yes | yes | yes | yes | yes | yes | test/features/handover_acceptance | HandoverAcceptanceMobileTest.php |
| `DELETE /journal-entries/{entry}` | Construction Journal | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/construction_journal | MobileApiContractDocumentationTest.php |
| `GET /journal-entries/{entry}` | Construction Journal | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/construction_journal | MobileApiContractDocumentationTest.php |
| `PUT /journal-entries/{entry}` | Construction Journal | yes | yes | yes | yes | yes | yes | yes | test/features/construction_journal | MobileApiContractDocumentationTest.php |
| `POST /journal-entries/{entry}/approve` | Construction Journal | yes | yes | yes | yes | yes | yes | yes | test/features/construction_journal | MobileApiContractDocumentationTest.php |
| `POST /journal-entries/{entry}/export/daily-report` | Construction Journal | yes | yes | yes | yes | yes | yes | yes | test/features/construction_journal | MobileApiContractDocumentationTest.php |
| `POST /journal-entries/{entry}/reject` | Construction Journal | yes | yes | yes | yes | yes | yes | yes | test/features/construction_journal | MobileApiContractDocumentationTest.php |
| `POST /journal-entries/{entry}/submit` | Construction Journal | yes | yes | yes | yes | yes | yes | yes | test/features/construction_journal | MobileApiContractDocumentationTest.php |
| `GET /machinery-operations/assets` | Machinery Operations | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/machinery_operations | MachineryOperationsMobileWorkflowTest.php |
| `POST /machinery-operations/downtimes` | Machinery Operations | yes | yes | yes | yes | yes | yes | yes | test/features/machinery_operations | MachineryOperationsMobileWorkflowTest.php |
| `POST /machinery-operations/fuel-issues` | Machinery Operations | yes | yes | yes | yes | yes | yes | yes | test/features/machinery_operations | MachineryOperationsMobileWorkflowTest.php |
| `POST /machinery-operations/production-records` | Machinery Operations | yes | yes | yes | yes | yes | yes | yes | test/features/machinery_operations | MachineryOperationsMobileWorkflowTest.php |
| `GET /machinery-operations/shift-reports` | Machinery Operations | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/machinery_operations | MachineryOperationsMobileWorkflowTest.php |
| `POST /machinery-operations/shift-reports` | Machinery Operations | yes | yes | yes | yes | yes | yes | yes | test/features/machinery_operations | MachineryOperationsMobileWorkflowTest.php |
| `POST /machinery-operations/shift-reports/{id}/submit` | Machinery Operations | yes | yes | yes | yes | yes | yes | yes | test/features/machinery_operations | MachineryOperationsMobileWorkflowTest.php |
| `GET /modules` | Modules | yes | yes | yes | yes | yes | yes | n/a for read route | test/core/providers/module_provider_test.dart | MobileModulesTest.php |
| `GET /notifications` | Notifications | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/notifications | MobileApiContractDocumentationTest.php |
| `DELETE /notifications/{id}` | Notifications | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/notifications | MobileApiContractDocumentationTest.php |
| `GET /notifications/{id}` | Notifications | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/notifications | MobileApiContractDocumentationTest.php |
| `POST /notifications/{id}/mark-read` | Notifications | yes | yes | yes | yes | yes | yes | yes | test/features/notifications | MobileApiContractDocumentationTest.php |
| `POST /notifications/{id}/mark-unread` | Notifications | yes | yes | yes | yes | yes | yes | yes | test/features/notifications | MobileApiContractDocumentationTest.php |
| `POST /notifications/mark-all-read` | Notifications | yes | yes | yes | yes | yes | yes | yes | test/features/notifications | MobileApiContractDocumentationTest.php |
| `GET /notifications/unread` | Notifications | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/notifications | MobileApiContractDocumentationTest.php |
| `GET /notifications/unread-count` | Notifications | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/notifications | MobileApiContractDocumentationTest.php |
| `GET /procurement/approvals` | Procurement | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/procurement | ProcurementMobileTest.php |
| `POST /procurement/approvals/{approval}/approve` | Procurement | yes | yes | yes | yes | yes | yes | yes | test/features/procurement | ProcurementMobileTest.php |
| `POST /procurement/approvals/{approval}/reject` | Procurement | yes | yes | yes | yes | yes | yes | yes | test/features/procurement | ProcurementMobileTest.php |
| `GET /procurement/purchase-orders` | Procurement | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/procurement | ProcurementMobileTest.php |
| `GET /procurement/purchase-orders/{purchaseOrder}` | Procurement | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/procurement | ProcurementMobileTest.php |
| `POST /procurement/purchase-orders/{purchaseOrder}/comments` | Procurement | yes | yes | yes | yes | yes | yes | yes | test/features/procurement | ProcurementMobileTest.php |
| `POST /procurement/purchase-orders/{purchaseOrder}/receive-materials` | Procurement | yes | yes | yes | yes | yes | yes | yes | test/features/procurement | ProcurementMobileTest.php |
| `GET /procurement/purchase-requests` | Procurement | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/procurement | ProcurementMobileTest.php |
| `GET /procurement/purchase-requests/{purchaseRequest}` | Procurement | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/procurement | ProcurementMobileTest.php |
| `GET /procurement/summary` | Procurement | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/procurement | ProcurementMobileTest.php |
| `POST /production-labor/output-entries` | Production Labor | yes | yes | yes | yes | yes | yes | yes | test/features/production_labor | ProductionLaborMobileWorkflowTest.php |
| `POST /production-labor/timesheets` | Production Labor | yes | yes | yes | yes | yes | yes | yes | test/features/production_labor | ProductionLaborMobileWorkflowTest.php |
| `GET /production-labor/work-orders` | Production Labor | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/production_labor | ProductionLaborMobileWorkflowTest.php |
| `GET /projects` | Projects | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/auth | MobileApiContractDocumentationTest.php |
| `GET /quality-control/defects` | Quality Control | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/quality_control | QualityControlMobileTest.php |
| `POST /quality-control/defects` | Quality Control | yes | yes | yes | yes | yes | yes | yes | test/features/quality_control | QualityControlMobileTest.php |
| `GET /quality-control/defects/{id}` | Quality Control | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/quality_control | QualityControlMobileTest.php |
| `POST /quality-control/defects/{id}/reject` | Quality Control | yes | yes | yes | yes | yes | yes | yes | test/features/quality_control | QualityControlMobileTest.php |
| `POST /quality-control/defects/{id}/resolve` | Quality Control | yes | yes | yes | yes | yes | yes | yes | test/features/quality_control | QualityControlMobileTest.php |
| `POST /quality-control/defects/{id}/start` | Quality Control | yes | yes | yes | yes | yes | yes | yes | test/features/quality_control | QualityControlMobileTest.php |
| `POST /quality-control/defects/{id}/verify` | Quality Control | yes | yes | yes | yes | yes | yes | yes | test/features/quality_control | QualityControlMobileTest.php |
| `GET /safety-management/incidents` | Safety Management | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/safety | SafetyManagementMobileWorkflowTest.php |
| `POST /safety-management/incidents` | Safety Management | yes | yes | yes | yes | yes | yes | yes | test/features/safety | SafetyManagementMobileWorkflowTest.php |
| `GET /safety-management/violations` | Safety Management | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/safety | SafetyManagementMobileWorkflowTest.php |
| `POST /safety-management/violations` | Safety Management | yes | yes | yes | yes | yes | yes | yes | test/features/safety | SafetyManagementMobileWorkflowTest.php |
| `POST /safety-management/violations/{id}/resolve` | Safety Management | yes | yes | yes | yes | yes | yes | yes | test/features/safety | SafetyManagementMobileWorkflowTest.php |
| `GET /safety-management/work-permits` | Safety Management | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/safety | SafetyManagementMobileWorkflowTest.php |
| `GET /safety-management/work-permits/{id}` | Safety Management | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/safety | SafetyManagementMobileWorkflowTest.php |
| `POST /safety-management/work-permits/{id}/activate` | Safety Management | yes | yes | yes | yes | yes | yes | yes | test/features/safety | SafetyManagementMobileWorkflowTest.php |
| `POST /safety-management/work-permits/{id}/approve` | Safety Management | yes | yes | yes | yes | yes | yes | yes | test/features/safety | SafetyManagementMobileWorkflowTest.php |
| `POST /safety-management/work-permits/{id}/close` | Safety Management | yes | yes | yes | yes | yes | yes | yes | test/features/safety | SafetyManagementMobileWorkflowTest.php |
| `POST /safety-management/work-permits/{id}/reject` | Safety Management | yes | yes | yes | yes | yes | yes | yes | test/features/safety | SafetyManagementMobileWorkflowTest.php |
| `POST /safety-management/work-permits/{id}/resume` | Safety Management | yes | yes | yes | yes | yes | yes | yes | test/features/safety | SafetyManagementMobileWorkflowTest.php |
| `POST /safety-management/work-permits/{id}/submit` | Safety Management | yes | yes | yes | yes | yes | yes | yes | test/features/safety | SafetyManagementMobileWorkflowTest.php |
| `POST /safety-management/work-permits/{id}/suspend` | Safety Management | yes | yes | yes | yes | yes | yes | yes | test/features/safety | SafetyManagementMobileWorkflowTest.php |
| `GET /schedule` | Schedule | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/schedule | ScheduleDailyPlanningWorkflowTest.php |
| `GET /schedule/{scheduleId}` | Schedule | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/schedule | ScheduleDailyPlanningWorkflowTest.php |
| `PATCH /schedule/daily-plan-assignments/{assignment}/fact` | Schedule | yes | yes | yes | yes | yes | yes | yes | test/features/schedule | ScheduleDailyPlanningWorkflowTest.php |
| `GET /schedule/daily-plans` | Schedule | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/schedule | ScheduleDailyPlanningWorkflowTest.php |
| `POST /schedule/daily-plans/{dailyPlan}/submit` | Schedule | yes | yes | yes | yes | yes | yes | yes | test/features/schedule | ScheduleDailyPlanningWorkflowTest.php |
| `POST /schedule/work-constraints/{constraint}/linked-action` | Schedule | yes | yes | yes | yes | yes | yes | yes | test/features/schedule | ScheduleDailyPlanningWorkflowTest.php |
| `GET /site-requests` | Site Requests | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/site_requests | SiteRequestsMobileTest.php |
| `POST /site-requests` | Site Requests | yes | yes | yes | yes | yes | yes | yes | test/features/site_requests | SiteRequestsMobileTest.php |
| `GET /site-requests/{id}` | Site Requests | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/site_requests | SiteRequestsMobileTest.php |
| `PUT /site-requests/{id}` | Site Requests | yes | yes | yes | yes | yes | yes | yes | test/features/site_requests | SiteRequestsMobileTest.php |
| `POST /site-requests/{id}/cancel` | Site Requests | yes | yes | yes | yes | yes | yes | yes | test/features/site_requests | SiteRequestsMobileTest.php |
| `POST /site-requests/{id}/complete` | Site Requests | yes | yes | yes | yes | yes | yes | yes | test/features/site_requests | SiteRequestsMobileTest.php |
| `POST /site-requests/{id}/status` | Site Requests | yes | yes | yes | yes | yes | yes | yes | test/features/site_requests | SiteRequestsMobileTest.php |
| `POST /site-requests/{id}/submit` | Site Requests | yes | yes | yes | yes | yes | yes | yes | test/features/site_requests | SiteRequestsMobileTest.php |
| `GET /site-requests/calendar` | Site Requests | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/site_requests | SiteRequestsMobileTest.php |
| `POST /site-requests/from-template/{templateId}` | Site Requests | yes | yes | yes | yes | yes | yes | yes | test/features/site_requests | SiteRequestsMobileTest.php |
| `PUT /site-requests/groups/{id}` | Site Requests | yes | yes | yes | yes | yes | yes | yes | test/features/site_requests | SiteRequestsMobileTest.php |
| `GET /site-requests/meta` | Site Requests | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/site_requests | SiteRequestsMobileTest.php |
| `GET /site-requests/templates` | Site Requests | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/site_requests | SiteRequestsMobileTest.php |
| `GET /time-tracking/daily-summary` | Time Tracking | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/time_tracking | TimeTrackingMobileTest.php |
| `GET /time-tracking/entries` | Time Tracking | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/time_tracking | TimeTrackingMobileTest.php |
| `POST /time-tracking/entries` | Time Tracking | yes | yes | yes | yes | yes | yes | yes | test/features/time_tracking | TimeTrackingMobileTest.php |
| `GET /time-tracking/entries/{entry}` | Time Tracking | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/time_tracking | TimeTrackingMobileTest.php |
| `POST /time-tracking/entries/{entry}/correction` | Time Tracking | yes | yes | yes | yes | yes | yes | yes | test/features/time_tracking | TimeTrackingMobileTest.php |
| `POST /time-tracking/entries/{entry}/stop` | Time Tracking | yes | yes | yes | yes | yes | yes | yes | test/features/time_tracking | TimeTrackingMobileTest.php |
| `POST /time-tracking/entries/{entry}/submit` | Time Tracking | yes | yes | yes | yes | yes | yes | yes | test/features/time_tracking | TimeTrackingMobileTest.php |
| `POST /time-tracking/timer/start` | Time Tracking | yes | yes | yes | yes | yes | yes | yes | test/features/time_tracking | TimeTrackingMobileTest.php |
| `GET /warehouse` | Warehouse | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/warehouse | ProjectMaterialDeliveryMobileSyncTest.php / warehouse widget tests |
| `GET /warehouse/balances/{warehouseId}/{materialId}/photos` | Warehouse | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/warehouse | ProjectMaterialDeliveryMobileSyncTest.php / warehouse widget tests |
| `POST /warehouse/balances/{warehouseId}/{materialId}/photos` | Warehouse | yes | yes | yes | yes | yes | yes | yes | test/features/warehouse | ProjectMaterialDeliveryMobileSyncTest.php / warehouse widget tests |
| `DELETE /warehouse/balances/{warehouseId}/{materialId}/photos/{fileId}` | Warehouse | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/warehouse | ProjectMaterialDeliveryMobileSyncTest.php / warehouse widget tests |
| `GET /warehouse/materials/autocomplete` | Warehouse | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/warehouse | ProjectMaterialDeliveryMobileSyncTest.php / warehouse widget tests |
| `GET /warehouse/movements/{movementId}/photos` | Warehouse | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/warehouse | ProjectMaterialDeliveryMobileSyncTest.php / warehouse widget tests |
| `POST /warehouse/movements/{movementId}/photos` | Warehouse | yes | yes | yes | yes | yes | yes | yes | test/features/warehouse | ProjectMaterialDeliveryMobileSyncTest.php / warehouse widget tests |
| `DELETE /warehouse/movements/{movementId}/photos/{fileId}` | Warehouse | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/warehouse | ProjectMaterialDeliveryMobileSyncTest.php / warehouse widget tests |
| `POST /warehouse/operations/receipt` | Warehouse | yes | yes | yes | yes | yes | yes | yes | test/features/warehouse | ProjectMaterialDeliveryMobileSyncTest.php / warehouse widget tests |
| `POST /warehouse/operations/transfer` | Warehouse | yes | yes | yes | yes | yes | yes | yes | test/features/warehouse | ProjectMaterialDeliveryMobileSyncTest.php / warehouse widget tests |
| `GET /warehouse/project-material-deliveries` | Warehouse | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/warehouse | ProjectMaterialDeliveryMobileSyncTest.php / warehouse widget tests |
| `GET /warehouse/project-material-deliveries/{deliveryId}` | Warehouse | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/warehouse | ProjectMaterialDeliveryMobileSyncTest.php / warehouse widget tests |
| `POST /warehouse/project-material-deliveries/{deliveryId}/receive` | Warehouse | yes | yes | yes | yes | yes | yes | yes | test/features/warehouse | ProjectMaterialDeliveryMobileSyncTest.php / warehouse widget tests |
| `GET /warehouse/project-material-deliveries/project-stock` | Warehouse | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/warehouse | ProjectMaterialDeliveryMobileSyncTest.php / warehouse widget tests |
| `POST /warehouse/scan/resolve` | Warehouse | yes | yes | yes | yes | yes | yes | yes | test/features/warehouse | ProjectMaterialDeliveryMobileSyncTest.php / warehouse widget tests |
| `GET /warehouse/warehouses/{warehouseId}/balances` | Warehouse | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/warehouse | ProjectMaterialDeliveryMobileSyncTest.php / warehouse widget tests |
| `GET /warehouse/warehouses/{warehouseId}/tasks` | Warehouse | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/warehouse | ProjectMaterialDeliveryMobileSyncTest.php / warehouse widget tests |
| `GET /warehouse/warehouses/{warehouseId}/tasks/{taskId}` | Warehouse | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/warehouse | ProjectMaterialDeliveryMobileSyncTest.php / warehouse widget tests |
| `POST /warehouse/warehouses/{warehouseId}/tasks/{taskId}/status` | Warehouse | yes | yes | yes | yes | yes | yes | yes | test/features/warehouse | ProjectMaterialDeliveryMobileSyncTest.php / warehouse widget tests |
| `GET /workflow-management/tasks` | Workflow Management | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/workflow_management | WorkflowManagementMobileTest.php |
| `GET /workflow-management/tasks/{task}` | Workflow Management | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/workflow_management | WorkflowManagementMobileTest.php |
| `POST /workflow-management/tasks/{task}/approve` | Workflow Management | yes | yes | yes | yes | yes | yes | yes | test/features/workflow_management | WorkflowManagementMobileTest.php |
| `POST /workflow-management/tasks/{task}/comments` | Workflow Management | yes | yes | yes | yes | yes | yes | yes | test/features/workflow_management | WorkflowManagementMobileTest.php |
| `POST /workflow-management/tasks/{task}/reject` | Workflow Management | yes | yes | yes | yes | yes | yes | yes | test/features/workflow_management | WorkflowManagementMobileTest.php |
| `POST /workflow-management/tasks/{task}/request-changes` | Workflow Management | yes | yes | yes | yes | yes | yes | yes | test/features/workflow_management | WorkflowManagementMobileTest.php |
| `GET /workforce/attendance/history` | Workforce | yes | yes | yes | yes | yes | yes | n/a for read route | test/features/workforce | WorkforceAttendanceQrWorkflowTest.php |
| `POST /workforce/attendance/qr` | Workforce | yes | yes | yes | yes | yes | yes | yes | test/features/workforce | WorkforceAttendanceQrWorkflowTest.php |
| `POST /workforce/attendance/qr/scan` | Workforce | yes | yes | yes | yes | yes | yes | yes | test/features/workforce | WorkforceAttendanceQrWorkflowTest.php |
| `POST /workforce/attendance/self` | Workforce | yes | yes | yes | yes | yes | yes | yes | test/features/workforce | WorkforceAttendanceQrWorkflowTest.php |
