/**
 * Made-up values shown in the designer so a layout can be judged before real data is printed.
 * None of these belong to a real person.
 */
const SAMPLE_VALUES: Record<string, string> = {
    full_name: 'Jane Doe',
    full_name_local: 'জেন ডো',
    date_of_birth: '14/05/1990',
    gender: 'Female',
    marital_status: 'Married',
    nationality: 'Bangladeshi',
    national_id: '1990123456789',
    passport_no: 'A01234567',
    blood_group: 'O+',
    religion: 'Islam',
    email: 'jane.doe@example.com',
    personal_phone: '01700-000000',
    emergency_phone: '01800-000000',
    present_address: 'House 1, Road 2, Dhaka',
    permanent_address: 'Village Example, Cumilla',
    region: 'Dhaka',
    sub_region: 'Gulshan',
    postal_code: '1212',
    height: "5' 4\"",
    weight: '58 kg',
    eye_color: 'Black',
    complexion: 'Fair',
    identification_mark: 'Mole on left cheek',
    employee_no: 'E-102345',
    secondary_no: 'S-7788',
    employment_type: 'Permanent',
    department: 'Operations',
    unit: 'Head Office',
    rank: 'Grade 5',
    designation: 'Senior Officer',
    branch: 'Administration',
    trade: 'Logistics',
    appointment: 'Section Head',
    joining_date: '01/02/2015',
    retirement_date: '13/05/2049',
    retired_id_no: 'R-0001',
    posting: 'Dhaka',
    company_name: 'Example Ltd',
    'family.name': 'John Doe',
    'family.name_local': 'জন ডো',
    'family.relation': 'Spouse',
    'family.date_of_birth': '02/09/1988',
    'family.gender': 'Male',
    'family.phone': '01900-000000',
    'family.blood_group': 'A+',
    'family.national_id': '1988123456789',
    'family.passport_no': 'B07654321',
    'family.identification_mark': 'Scar on right hand',
    'family.card_no': 'F-0042',
    'card.number': 'EMP/26000123',
    'card.issue_date': '04/10/2026',
    'card.expiry_date': '03/10/2028',
    // Same length as a real link of this application, so a QR code shows its real size
    'card.verify_url': `${typeof window === 'undefined' ? '' : window.location.origin}/v/k3m9x2p7q4w8z1n5r6t0y3u8`,
    'signatory.name': 'A. Signatory',
    'signatory.designation': 'Director, HR',
};

/**
 * Value to show for a data field; custom fields show their label in angle brackets.
 */
export function sampleValue(field: string | null, overrides: Record<string, string> = {}): string {
    if (!field) {
        return '';
    }

    return overrides[field] ?? SAMPLE_VALUES[field] ?? `‹${field.replace(/^custom\./, '')}›`;
}
