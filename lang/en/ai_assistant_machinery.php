<?php

declare(strict_types=1);

return [
    'description' => 'Project machinery linked through its record and canonical registry; current time-bound assignments, recorded unfinished downtime and open maintenance. Complete authorized counts, bounded examples and current source checks. Missing assignment does not prove downtime.',
    'scope' => 'Project :project machinery on :date. Registry and recorded operation data.',
    'assets' => 'Linked machinery: :total. Shown :shown.',
    'limit' => 'Complete machinery read limit exceeded (1000 records). No count is inferred from a partial sample.',
    'machinery_assignment' => 'Current active assignments: :count.',
    'machinery_downtime' => 'Recorded unfinished downtime: :count.',
    'machinery_maintenance_order' => 'Open repairs and maintenance: :count.',
    'asset_id' => 'machinery #:id',
    'status_available' => 'available', 'status_assigned' => 'assigned', 'status_in_operation' => 'operating',
    'status_under_maintenance' => 'under maintenance', 'status_out_of_service' => 'out of service', 'status_archived' => 'archived', 'status_unknown' => 'status needs review',
    'technical_serviceable' => 'serviceable', 'technical_restricted' => 'use restricted', 'technical_maintenance' => 'maintenance',
    'technical_unavailable' => 'technically unavailable', 'technical_unknown' => 'technical state not established',
    'unavailable' => 'Operation section inaccessible or exceeds complete-read limit. Count unknown.',
    'action' => 'Prioritize open maintenance and use restrictions, then recorded downtime causes and restart readiness. Open the relevant machinery record and agree action with its owner. With no records, the corresponding registry risk is not established.',
    'basis' => 'No record does not prove physical absence of machinery or downtime. Available status or missing assignment alone does not imply downtime. Repair causes, actual readiness and schedule impact are not separately established.',
];
