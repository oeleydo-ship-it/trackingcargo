import type { PageProps as InertiaPageProps } from '@inertiajs/core';

export interface CompanySummary {
    id: number;
    name: string;
}

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    isPlatformAdmin: boolean;
    isDriver: boolean;
    company: CompanySummary | null;
}

export interface Company {
    id: number;
    code: string;
    name: string;
    legal_name: string | null;
    email: string | null;
    phone: string | null;
    country_code: string | null;
    timezone: string;
    default_currency: string;
    tracking_number_format: string;
    tracking_sequence_padding: number;
    allow_manual_tracking_number: boolean;
    default_tracking_mode: TrackingNumberMode;
    batch_number_format: string;
    batch_sequence_padding: number;
    status: string;
}

/** The company's tracking-number preferences, as the booking form needs them. */
/** A branch/mode override of the company's default tracking-number pattern. */
export interface TrackingFormatRule {
    branch_id: number | null;
    mode: ShipmentMode | null;
    format: string;
    padding: number;
}

/** How a tracking number is produced at booking: generated, a receipt/reference in the pattern, or typed in full. */
export type TrackingNumberMode = 'auto' | 'suffix' | 'full';

export interface TrackingSettings {
    companyCode: string;
    format: string;
    padding: number;
    allowManual: boolean;
    /** The method the booking form starts with. */
    defaultMode: TrackingNumberMode;
    /** Branches that start with a different method than the company default. */
    branchModes: { branch_id: number; mode: TrackingNumberMode }[];
    rules: TrackingFormatRule[];
}

export type BatchStatus = 'open' | 'closed';

/** A group of shipments moved through status transitions together. */
export interface ShipmentBatch {
    id: number;
    branch_id: number;
    batch_number: string;
    reference: string | null;
    status: BatchStatus;
    notes: string | null;
    created_at: string;
    branch?: { id: number; name: string } | null;
    creator?: { id: number; name: string } | null;
    shipments_count?: number;
}

/** Open batches offered by the booking form, from ShipmentController::openBatches(). */
export interface BatchOption {
    id: number;
    branch_id: number;
    batch_number: string;
    reference: string | null;
}

/** An unbatched shipment offered by the batch page's add-shipments picker. */
export interface AssignableShipment {
    id: number;
    tracking_number: string;
    status: string;
    destination_city: string | null;
    destination_country_code: string;
}

/** Slim branch shape used by pickers, from ShipmentController::bookableBranches(). */
export interface BranchOption {
    id: number;
    name: string;
    code: string;
    tracking_prefix: string;
}

export interface Branch {
    id: number;
    code: string;
    name: string;
    tracking_prefix: string;
    email: string | null;
    phone: string | null;
    country_code: string;
    city: string;
    address: string | null;
    timezone: string;
    status: string;
    is_head_office: boolean;
}

export interface Permission {
    id: number;
    slug: string;
    name: string;
    group: string;
}

export interface Role {
    id: number;
    name: string;
    slug: string;
    is_system: boolean;
    permissions: Permission[];
}

export interface UserSummary {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    branch_id: number | null;
    branch: { id: number; name: string } | null;
    status: string;
    roles: Role[];
}

export interface CustomerContact {
    id: number;
    name: string;
    title: string | null;
    email: string | null;
    phone: string | null;
    is_primary: boolean;
    notes: string | null;
}

export interface Address {
    id: number;
    type: 'billing' | 'shipping' | 'pickup' | 'delivery' | 'other';
    label: string | null;
    line1: string;
    line2: string | null;
    city: string;
    state: string | null;
    postal_code: string | null;
    country_code: string;
    contact_name: string | null;
    contact_phone: string | null;
    is_default: boolean;
}

export interface CustomerNote {
    id: number;
    body: string;
    is_pinned: boolean;
    author: { id: number; name: string } | null;
    created_at: string;
}

export interface CustomerSummary {
    id: number;
    customer_number: string;
    type: 'individual' | 'business';
    name: string;
    company_name: string | null;
    email: string | null;
    phone: string | null;
    status: 'active' | 'inactive' | 'blocked';
    branch: { id: number; name: string } | null;
}

export interface Customer extends CustomerSummary {
    tax_id: string | null;
    identification_number: string | null;
    credit_limit: string | null;
    portal_user: { id: number; name: string; email: string } | null;
    contacts: CustomerContact[];
    addresses: Address[];
    notes: CustomerNote[];
    invoices: InvoiceSummary[];
}

export type ShipmentMode = 'air' | 'sea' | 'road' | 'courier';

/**
 * A status code. No longer a fixed union: statuses are per-company rows a
 * company defines in Settings, so the code is whatever was generated from the
 * name they chose. Render `ShipmentStatusRef.name`, never the raw code.
 */
export type ShipmentStatus = string;

/** The status row joined onto a shipment, for display. */
export interface ShipmentStatusRef {
    id: number;
    code: string;
    name: string;
    color: string;
    role?: string | null;
    is_public?: boolean;
    is_terminal?: boolean;
    is_initial?: boolean;
    is_active?: boolean;
}

/**
 * Cargo-side parties on a shipment. The carrier moving the box is not one of
 * them — that is `carrier_code` on the shipment itself.
 */
export type ShipmentPartyRole = 'consignor' | 'consignee' | 'notify';

export interface ShipmentParty {
    id: number;
    role: ShipmentPartyRole;
    customer_id: number | null;
    customer?: { id: number; name: string; customer_number: string } | null;
    name: string;
    company_name: string | null;
    email: string | null;
    phone: string | null;
    tax_id: string | null;
    addresses?: Address[];
}

/** A customer offered in the booking form's consignor/consignee picker. */
export interface CustomerOption {
    id: number;
    name: string;
    company_name: string | null;
    email: string | null;
    phone: string | null;
    tax_id: string | null;
    customer_number: string;
    addresses: Address[];
}

export interface BoxSize {
    id: number;
    box_id: number;
    name: string;
    /** Dimensions are measured on each package (Odd Size, Crate). */
    is_custom: boolean;
    length_cm: string | null;
    width_cm: string | null;
    height_cm: string | null;
    is_active: boolean;
    /** Packages booked with this size; present on the Settings → Boxes page. */
    packages_count?: number;
}

export interface Box {
    id: number;
    name: string;
    is_active: boolean;
    sizes: BoxSize[];
}

export interface ShipmentPackage {
    id: number;
    package_number: number;
    /** Identical pieces this row stands for; weight and dimensions are per piece. */
    pieces: number;
    barcode: string;
    description: string | null;
    weight_kg: string;
    length_cm: string | null;
    width_cm: string | null;
    height_cm: string | null;
    volumetric_weight_kg: string;
    declared_value: string | null;
    box_size_id: number | null;
    box_size: (Pick<BoxSize, 'id' | 'name'> & { box: { id: number; name: string } }) | null;
}

export interface TrackingEvent {
    id: number;
    from_status: ShipmentStatus | null;
    to_status: ShipmentStatus;
    location: string | null;
    description: string | null;
    is_public: boolean;
    occurred_at: string;
    created_by: { id: number; name: string } | null;
}

export interface ShipmentSummary {
    id: number;
    tracking_number: string;
    mode: ShipmentMode;
    status: ShipmentStatus;
    shipment_status: ShipmentStatusRef | null;
    destination_country_code: string | null;
    destination_city: string | null;
    package_count: number;
    chargeable_weight_kg: string;
    branch: { id: number; name: string } | null;
    customer: { id: number; name: string } | null;
}

/** The search and filter values on the shipments list; blank means "not filtering by this". */
export interface ShipmentFilters {
    q: string;
    status: string;
    mode: string;
    branch_id: string;
    carrier_id: string;
    country: string;
    from: string;
    to: string;
}

/** The search and filter values on the batches list; blank means "not filtering by this". */
export interface BatchFilters {
    q: string;
    status: string;
    branch_id: string;
    from: string;
    to: string;
}

/** The branches the batches list's filter offers — only ones that have a batch the viewer can see. */
export interface BatchFilterOptions {
    branches: { id: number; name: string }[];
}

/** The choices the shipments list's dropdowns offer — only values present on shipments the viewer can see. */
export interface ShipmentFilterOptions {
    statuses: { code: string; name: string; color: string }[];
    branches: { id: number; name: string }[];
    carriers: { id: number; name: string }[];
    countries: string[];
}

export type RouteLegStatus = 'planned' | 'loaded' | 'departed' | 'arrived' | 'completed' | 'cancelled';

export interface RouteLeg {
    id: number;
    sequence: number;
    mode: ShipmentMode;
    master_id: number | null;
    origin_location: string;
    destination_location: string;
    status: RouteLegStatus;
    scheduled_departure_at: string | null;
    scheduled_arrival_at: string | null;
    actual_departure_at: string | null;
    actual_arrival_at: string | null;
}

export type CustomsClearanceStatus = 'pending' | 'under_review' | 'cleared' | 'held' | 'rejected';

export interface CustomsClearanceSummary {
    id: number;
    shipment_id: number;
    status: CustomsClearanceStatus;
    declaration_number: string | null;
    customs_office: string | null;
    submitted_at: string | null;
    cleared_at: string | null;
}

export interface CustomsQueueItem extends CustomsClearanceSummary {
    shipment: { id: number; tracking_number: string; destination_country_code: string | null; destination_city: string | null };
    branch: { id: number; name: string } | null;
}

/** A carrier from Settings → Carriers, as offered on the booking form. */
export interface CarrierOption {
    id: number;
    name: string;
    code: string;
    /** Modes the carrier serves; null means any. */
    modes: ShipmentMode[] | null;
    is_active: boolean;
}

export interface Carrier extends CarrierOption {
    integration_code: string | null;
    website: string | null;
    contact_name: string | null;
    contact_email: string | null;
    contact_phone: string | null;
    shipments_count?: number;
}

export interface Shipment extends ShipmentSummary {
    branch_id: number;
    batch_id: number | null;
    batch?: { id: number; batch_number: string; reference: string | null } | null;
    customer_id: number | null;
    carrier_id: number | null;
    carrier?: Pick<Carrier, 'id' | 'name' | 'code' | 'website' | 'contact_name' | 'contact_email' | 'contact_phone' | 'is_active'> | null;
    carrier_code: string | null;
    origin_country_code: string | null;
    declared_weight_kg: string;
    volumetric_weight_kg: string;
    currency: string;
    declared_value: string | null;
    /** How the customer pays for the shipment, if recorded. */
    payment_mode: string | null;
    last_location: string | null;
    last_status_at: string | null;
    booked_at: string | null;
    delivered_at: string | null;
    parties: ShipmentParty[];
    packages: ShipmentPackage[];
    tracking_events: TrackingEvent[];
    route_legs: RouteLeg[];
    customs_clearances: CustomsClearanceSummary[];
    delivery_assignments: DeliveryAssignmentSummary[];
}

export type DutyType = 'duty' | 'tax' | 'fee';

export interface CustomsDuty {
    id: number;
    type: DutyType;
    description: string;
    amount: string;
    currency: string;
    is_paid: boolean;
    paid_at: string | null;
}

export type InspectionType = 'physical' | 'xray' | 'documentary' | 'canine' | 'other';

export type InspectionStatus = 'scheduled' | 'passed' | 'failed';

export interface CustomsInspection {
    id: number;
    type: InspectionType;
    status: InspectionStatus;
    scheduled_at: string | null;
    completed_at: string | null;
    notes: string | null;
}

export type DocumentCategory = 'customs_declaration' | 'commercial_invoice' | 'packing_list' | 'certificate_of_origin' | 'import_permit' | 'other';

export interface CustomsDocument {
    id: number;
    category: DocumentCategory;
    original_filename: string;
    mime_type: string | null;
    size_bytes: number;
    uploaded_by: { id: number; name: string } | null;
    created_at: string;
}

export interface CustomsClearance extends CustomsClearanceSummary {
    notes: string | null;
    duties: CustomsDuty[];
    inspections: CustomsInspection[];
    documents: CustomsDocument[];
}

export type DriverStatus = 'active' | 'inactive' | 'suspended';

export interface DriverSummary {
    id: number;
    license_number: string | null;
    phone: string | null;
    status: DriverStatus;
    user: { id: number; name: string; email: string };
    branch: { id: number; name: string } | null;
    zone: { id: number; name: string } | null;
}

export type VehicleType = 'van' | 'truck' | 'motorcycle' | 'car';

export type VehicleStatus = 'active' | 'maintenance' | 'inactive';

export interface Vehicle {
    id: number;
    registration_number: string;
    type: VehicleType;
    capacity_kg: string | null;
    status: VehicleStatus;
    branch: { id: number; name: string } | null;
}

export interface DeliveryZoneSummary {
    id: number;
    code: string;
    name: string;
    branch: { id: number; name: string } | null;
}

export type DeliveryAssignmentStatus = 'assigned' | 'out_for_delivery' | 'delivered' | 'cancelled';

export interface DeliveryAssignmentSummary {
    id: number;
    shipment_id: number;
    status: DeliveryAssignmentStatus;
    scheduled_date: string | null;
    delivered_at: string | null;
}

export type DeliveryAttemptOutcome = 'succeeded' | 'failed';

export interface DeliveryAttemptDocument {
    id: number;
    category: DocumentCategory;
    original_filename: string;
}

export interface DeliveryAttempt {
    id: number;
    outcome: DeliveryAttemptOutcome;
    attempted_at: string;
    recipient_name: string | null;
    failure_reason: string | null;
    reschedule_date: string | null;
    collected_amount: string | null;
    collected_currency: string | null;
    notes: string | null;
    actor: { id: number; name: string } | null;
    documents: DeliveryAttemptDocument[];
}

export interface DeliveryAssignment extends DeliveryAssignmentSummary {
    notes: string | null;
    driver: { id: number; user: { id: number; name: string; email: string } };
    vehicle: Vehicle | null;
    zone: { id: number; name: string } | null;
    attempts: DeliveryAttempt[];
}

export interface DriverLocationPing {
    driver_id: number;
    driver_name: string;
    latitude: number;
    longitude: number;
    recorded_at: string;
}

export interface DispatchBoardAssignment {
    id: number;
    shipment_id: number;
    status: DeliveryAssignmentStatus;
    shipment: { id: number; tracking_number: string; destination_city: string | null };
    driver: { id: number; user: { id: number; name: string } };
}

export interface DispatchAttemptFeedItem {
    id: number;
    outcome: DeliveryAttemptOutcome;
    outcome_label: string;
    shipment_tracking_number: string;
    driver_name: string;
    recipient_name: string | null;
    failure_reason: string | null;
    attempted_at: string;
}

export type MasterMode = 'air' | 'sea';

export type MasterStatus = 'open' | 'closed' | 'departed' | 'arrived' | 'closed_out' | 'cancelled';

export type LoadUnitType = 'container' | 'pallet' | 'bag';

export type LoadUnitStatus = 'building' | 'loaded' | 'in_transit' | 'arrived' | 'unloaded';

export interface ManifestPackageLine {
    barcode: string;
    shipment_tracking_number: string;
    description: string | null;
    weight_kg: number;
}

export interface ManifestLoadUnitLine {
    type: LoadUnitType;
    unit_number: string;
    seal_number: string | null;
    package_count: number;
    weight_kg: number;
    packages: ManifestPackageLine[];
}

export interface Manifest {
    id: number;
    manifest_number: string;
    version: number;
    generated_by: { id: number; name: string } | null;
    created_at: string;
    snapshot: {
        master_number: string;
        mode: MasterMode;
        conveyance: string;
        origin: string | null;
        destination: string | null;
        status: MasterStatus;
        package_count: number;
        weight_kg: number;
        generated_at: string;
        load_units: ManifestLoadUnitLine[];
    };
}

export interface LoadUnitPackageSummary {
    id: number;
    barcode: string;
    weight_kg: string;
    shipment: { id: number; tracking_number: string };
}

export interface LoadUnit {
    id: number;
    master_id: number;
    type: LoadUnitType;
    unit_number: string;
    seal_number: string | null;
    status: LoadUnitStatus;
    package_count: number;
    weight_kg: string;
    packages: LoadUnitPackageSummary[];
}

export interface MasterSummary {
    id: number;
    master_number: string;
    mode: MasterMode;
    status: MasterStatus;
    branch: { id: number; name: string } | null;
    package_count: number;
    weight_kg: string;
}

export interface Master extends MasterSummary {
    carrier_code: string | null;
    flight_number: string | null;
    origin_airport: string | null;
    destination_airport: string | null;
    shipping_line: string | null;
    vessel_name: string | null;
    voyage_number: string | null;
    origin_port: string | null;
    destination_port: string | null;
    scheduled_departure_at: string | null;
    scheduled_arrival_at: string | null;
    actual_departure_at: string | null;
    actual_arrival_at: string | null;
    load_units: LoadUnit[];
    manifests: Manifest[];
}

export interface PublicTrackingEvent {
    status: ShipmentStatus;
    status_label: string;
    location: string | null;
    description: string | null;
    occurred_at: string;
}

/** Each field is null when the company has hidden it — see PublicTrackingFieldPolicy. */
export interface PublicTrackingParty {
    name: string | null;
    company_name: string | null;
    phone: string | null;
    email: string | null;
    address_lines: string[];
    city: string | null;
    state: string | null;
    postal_code: string | null;
    country_code: string | null;
}

/** The role -> field -> visibility matrix edited under Settings -> Public tracking. */
export type PublicTrackingPartySettings = Record<'sender' | 'receiver', Record<string, string>>;

export interface PublicShipment {
    tracking_number: string;
    carrier: string;
    status: ShipmentStatus;
    status_label: string;
    status_color: string;
    mode: ShipmentMode;
    origin_country_code: string | null;
    destination_country_code: string | null;
    destination_city: string | null;
    last_location: string | null;
    last_status_at: string | null;
    package_count: number;
    sender: PublicTrackingParty | null;
    receiver: PublicTrackingParty | null;
    events: PublicTrackingEvent[];
}

export type WarehouseZoneType = 'receiving' | 'storage' | 'staging' | 'dispatch' | 'customs_hold' | 'returns';

export type WarehouseScanType = 'receive' | 'sort' | 'load' | 'unload' | 'dispatch';

export interface WarehouseLocation {
    id: number;
    zone_id: number;
    code: string;
    package_count: number;
    is_active: boolean;
}

export interface WarehouseZone {
    id: number;
    warehouse_id: number;
    code: string;
    name: string;
    type: WarehouseZoneType;
    is_active: boolean;
    locations: WarehouseLocation[];
}

export interface WarehouseSummary {
    id: number;
    code: string;
    name: string;
    city: string | null;
    country_code: string | null;
    is_active: boolean;
    branch: { id: number; name: string } | null;
    locations_count: number;
}

export interface Warehouse extends WarehouseSummary {
    zones: WarehouseZone[];
}

export interface PackageScan {
    id: number;
    scan_type: WarehouseScanType;
    scan_type_label: string;
    package_barcode: string;
    shipment_tracking_number: string;
    warehouse_name: string;
    from_location_code: string | null;
    to_location_code: string | null;
    scanned_by: string | null;
    occurred_at: string;
}

export interface RateCardTier {
    id: number;
    min_weight_kg: string;
    max_weight_kg: string | null;
    price_per_kg: string;
}

export interface RateCard {
    id: number;
    name: string;
    mode: ShipmentMode | null;
    currency: string;
    base_fee: string;
    min_charge: string;
    is_active: boolean;
    branch: { id: number; name: string } | null;
    customer: { id: number; name: string } | null;
    tiers: RateCardTier[];
}

export type InvoiceStatus = 'draft' | 'issued' | 'partially_paid' | 'paid' | 'void';

export type PaymentMethod = 'cash' | 'bank_transfer' | 'card' | 'cod';

export interface InvoiceItem {
    id: number;
    shipment_id: number | null;
    shipment: { id: number; tracking_number: string } | null;
    description: string;
    quantity: string;
    unit_price: string;
    amount: string;
}

export interface Payment {
    id: number;
    amount: string;
    currency: string;
    method: PaymentMethod;
    reference: string | null;
    received_at: string;
    notes: string | null;
    actor: { id: number; name: string } | null;
}

export interface InvoiceSummary {
    id: number;
    invoice_number: string;
    status: InvoiceStatus;
    currency: string;
    total: string;
    balance_due: string;
    due_date: string | null;
}

export interface Invoice extends InvoiceSummary {
    subtotal: string;
    tax_amount: string;
    amount_paid: string;
    issue_date: string | null;
    notes: string | null;
    items: InvoiceItem[];
    payments: Payment[];
}

export interface CodRemittance {
    id: number;
    amount: string;
    currency: string;
    remitted_at: string;
    notes: string | null;
    driver: { id: number; user: { id: number; name: string } };
    actor: { id: number; name: string } | null;
}

export type WebhookEventType = '*' | 'shipment.status_changed' | 'invoice.paid';

export type WebhookDeliveryStatus = 'pending' | 'succeeded' | 'failed';

export interface WebhookDelivery {
    id: number;
    event_type: string;
    status: WebhookDeliveryStatus;
    attempts: number;
    response_status: number | null;
    response_excerpt: string | null;
    last_attempted_at: string | null;
    created_at: string;
}

export interface WebhookEndpoint {
    id: number;
    url: string;
    description: string | null;
    event_types: WebhookEventType[];
    is_active: boolean;
    deliveries: WebhookDelivery[];
}

export type NotificationTypeValue = 'shipment.delivered' | 'invoice.issued' | 'webhook.delivery-failed';

export interface NotificationTypePreference {
    value: NotificationTypeValue;
    label: string;
    channels: { channel: string; enabled: boolean }[];
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface SharedPageProps extends InertiaPageProps {
    auth: {
        user: AuthUser | null;
    };
    flash: {
        success: string | null;
        error: string | null;
    };
    branding: {
        siteName: string;
        logoUrl: string | null;
        faviconUrl: string | null;
    };
    /** True while a superadmin has public workspace sign-up switched on. */
    registrationEnabled: boolean;
}
