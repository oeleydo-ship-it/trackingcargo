# Branch validation and country selection

Shipment creation, editing, package selection, and shipment/batch status validation now use the resolved TenantContext company. Platform administrators acting as a company have no company_id of their own; validating against that empty value previously rejected valid branch/customer/box/status selections. Cross-company choices remain rejected.

CountrySelect provides ISO alpha-2 values with full English country names in shipment creation/editing, consignor/consignee addresses, and branch creation/editing. Existing address fields retain their data. Branch addresses use a multiline field for complete street/building/unit/district/province/postal details. No database migration is needed.

Regression coverage in ShipmentManagementTest verifies that an acting platform admin can create and transition a shipment for the selected company while a foreign branch is rejected.
