# Receipt-based shipment tracking

On Shipments → New shipment, choose a numbering method:

- Generate automatically: allocate the next unused sequence number.
- Company / branch + receipt reference: replace the configured `{sequence}` section with the entered reference, e.g. `GLX-DXB-OTHER-0012`. This is available to shipment creators without enabling full-number overrides.
- Enter entire tracking number: available when the existing company setting allows full manual numbers.

The receipt option preserves the company's configured format, including company/branch prefixes and date tokens. References support letters, digits, dashes, underscores and dots; leading zeros are preserved. The complete number is limited to 40 characters. Duplicate numbers, including those belonging to deleted shipments, cannot be reused. Package barcodes use the resulting tracking number. Automatic allocation skips numbers already reserved by manual entries.

Implementation: `StoreShipmentRequest`, `ShipmentService`, and the React shipment index. Regression coverage is in `ShipmentManagementTest`. No database migration is required. Existing shipments are unchanged.
