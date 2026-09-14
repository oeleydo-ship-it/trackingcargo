# Late shipment status updates

In a shipment's Update status panel, choose a status and optionally set Status date and time. For cargo received yesterday, select yesterday's actual arrival time and apply the update today. Blank uses the current time.

The input uses the browser's local timezone (shown under the field) and submits an offset-aware timestamp; the server stores UTC. Future dates are rejected. Existing transition permissions and company workflow rules still apply.

Tracking event occurred_at, shipment last_status_at, and booked_at/delivered_at when relevant use the selected time. Event created_at and audit timestamps retain the actual entry time; audit details include occurred_at. Public tracking and status webhooks use the event time. Previously recorded events remain immutable. This option records a new transition, not an edit to an old event, and requires no migration.

Implementation: TransitionShipmentRequest, ShipmentTransitionController, ShipmentTransitionService, and the React shipment Show page. Coverage: ShipmentTransitionTest.
