# Vibesoft CRM — Production Android REST API Reference

This document serves as the complete, production-hardened API specification for the Android application. All endpoints adhere to strict RESTful principles, return predictable JSON envelopes, enforce server-side role-based authorization (RBAC), shield sensitive data, and provide safe HTTP status codes.

---

## 1. Global API Standards & Conventions

### Base URL
```
http://<host>/Vibesoft_CRM/Crm%20and%20backend/api
```

### Standard Request Headers
| Header | Value | Requirement |
|---|---|---|
| `Content-Type` | `application/json` | Required for POST/PUT |
| `Accept` | `application/json` | Recommended |
| `Authorization` | `Bearer <access_token>` | Required on all protected endpoints |

---

### Standard Response Envelopes

#### 1. Success Response Envelope (HTTP 200 / 201)
```json
{
  "success": true,
  "message": "Resource loaded successfully",
  "data": { ... },
  "pagination": {
    "total": 120,
    "page": 1,
    "limit": 20,
    "total_pages": 6,
    "has_more": true
  }
}
```
*Note: For maximum compatibility across Android Retrofit / Gson / Kotlin Serialization and legacy components, collection data is provided both within the `data` wrapper and as top-level keys (e.g. `"customers": [...]`).*

#### 2. Error Response Envelope (HTTP 400, 401, 403, 404, 409, 422, 429, 500)
```json
{
  "success": false,
  "error": "Error title or description",
  "message": "Human-readable production-safe message",
  "code": "MACHINE_READABLE_ERROR_CODE",
  "errors": {
    "field_name": [
      "The field_name is required or invalid."
    ]
  }
}
```

---

### HTTP Status Codes & Error Codes
| HTTP Code | Description | Standard `code` values |
|---|---|---|
| **200 OK** | Successful read, update, or deletion | — |
| **201 Created** | Record successfully created | — |
| **400 Bad Request** | Missing query parameter, business rule violation | `INVALID_INPUT`, `FOREIGN_KEY_VIOLATION`, `HAS_PAYMENTS` |
| **401 Unauthorized** | Missing, expired, or invalid bearer token | `TOKEN_MISSING`, `INVALID_TOKEN`, `TOKEN_EXPIRED`, `TOKEN_REVOKED`, `INVALID_CREDENTIALS` |
| **403 Forbidden** | Insufficient role or cross-tenant IDOR attack | `FORBIDDEN`, `IDOR_ACCESS_DENIED`, `ACCOUNT_INACTIVE` |
| **404 Not Found** | Record does not exist | `NOT_FOUND`, `CUSTOMER_NOT_FOUND`, `INVOICE_NOT_FOUND` |
| **409 Conflict** | Duplicate entry on unique field (email, code, etc.) | `DUPLICATE_RECORD` |
| **422 Unprocessable** | Input validation rules failed | `VALIDATION_FAILED` |
| **429 Too Many Requests** | Rate limit exceeded | `RATE_LIMIT_EXCEEDED` (includes `Retry-After` header) |
| **500 Server Error** | Safe database error (zero raw SQL or stack traces leaked) | `SERVER_ERROR`, `DATABASE_ERROR` |

---

### Sensitive Data Masking Policy
- `password_hash`, `token_hash`, `session_id_hash`, `auth_token_hash`, and `salt` are **permanently stripped** from all JSON outputs.
- Bank Account Numbers are masked showing only the last 4 digits: `********9012`.
- PAN card numbers are masked: `ABC****4F`.
- Aadhaar numbers are masked: `********9012`.
- Vault passwords are only revealed via the dedicated, audited `POST /api/vault.php?action=reveal` endpoint.

---

## 2. Authentication Endpoints (`/api/mobile_auth.php`)

### 2.1 Login
- **Method**: `POST`
- **URL**: `/api/mobile_auth.php?action=login`
- **Auth**: None (Public)
- **Rate Limit**: Max 5 consecutive failed attempts per 15-minute window.

#### Request Body
```json
{
  "username": "admin@vibesoft.in",
  "password": "your_secure_password"
}
```
*(Accepts Email, Username, or Employee Code e.g. `EMP101`)*

#### Success Response (`HTTP 200 OK`)
```json
{
  "success": true,
  "message": "Login successful",
  "token": "64_character_hex_bearer_token",
  "token_type": "Bearer",
  "expires_at": "2026-10-08 17:30:00",
  "user": {
    "id": 1,
    "name": "Super Admin",
    "email": "admin@vibesoft.in",
    "role": "admin",
    "employee_id": null
  }
}
```

#### Error Response (`HTTP 401 Unauthorized`)
```json
{
  "success": false,
  "error": "Invalid login credentials. (4 attempts remaining)",
  "message": "Invalid login credentials. (4 attempts remaining)",
  "code": "INVALID_CREDENTIALS",
  "errors": {
    "attempts_remaining": 4
  }
}
```

---

### 2.2 Token Refresh
- **Method**: `POST`
- **URL**: `/api/mobile_auth.php?action=refresh`
- **Header**: `Authorization: Bearer <current_valid_token>`

#### Success Response (`HTTP 200 OK`)
```json
{
  "success": true,
  "message": "Token refreshed successfully",
  "token": "new_64_character_hex_bearer_token",
  "token_type": "Bearer",
  "expires_at": "2026-10-08 17:35:00",
  "expires_in": 2592000
}
```

---

### 2.3 Logout
- **Method**: `POST`
- **URL**: `/api/mobile_auth.php?action=logout`
- **Header**: `Authorization: Bearer <token>`

#### Success Response (`HTTP 200 OK`)
```json
{
  "success": true,
  "message": "Logged out successfully"
}
```

---

## 3. Customer Management (`/api/customers.php`)

### 3.1 List Customers
- **Method**: `GET`
- **URL**: `/api/customers.php`
- **Query Parameters**:
  - `page` (int, default: 1)
  - `limit` (int, default: 20, max: 100)
  - `q` (string, search term across name, email, phone, GSTIN)
  - `type` (`monthly`, `one_time`, `project`, `retainer`)
  - `status` (`lead`, `active`, `inactive`, `completed`, `archived`)
  - `sort` (`id`, `name`, `created_at`, `monthly_amount`, `status`)
  - `order` (`asc`, `desc`)

#### Success Response (`HTTP 200 OK`)
```json
{
  "success": true,
  "message": "Customers loaded",
  "data": {
    "items": [
      {
        "id": 12,
        "name": "Acme Global Technologies",
        "client_type": "monthly",
        "status": "active",
        "contact_person": "Robert Vance",
        "phone": "9876543210",
        "email": "robert@acmeglobal.com",
        "monthly_service": "Cloud & ERP Maintenance",
        "monthly_amount": 35000.00,
        "billing_day": 1,
        "due_days": 10,
        "total_billed": 105000.00,
        "total_received": 70000.00,
        "outstanding_balance": 35000.00,
        "created_at": "2026-03-01 10:00:00"
      }
    ]
  },
  "pagination": {
    "total": 45,
    "page": 1,
    "limit": 20,
    "total_pages": 3,
    "has_more": true
  }
}
```

---

### 3.2 Customer Detail with Financial Metrics
- **Method**: `GET`
- **URL**: `/api/customers.php?id={id}`

#### Success Response (`HTTP 200 OK`)
```json
{
  "success": true,
  "message": "Customer detail loaded",
  "data": {
    "customer": {
      "id": 12,
      "name": "Acme Global Technologies",
      "total_billed": 105000.00,
      "total_received": 70000.00,
      "outstanding_balance": 35000.00,
      "ledger_balance": 35000.00,
      "recent_invoices": [
        {
          "id": 84,
          "invoice_no": "INV-10042",
          "total_amount": 35000.00,
          "amount_paid": 0.00,
          "amount_due": 35000.00,
          "status": "sent"
        }
      ],
      "recent_payments": []
    }
  }
}
```

---

### 3.3 Create Customer
- **Method**: `POST`
- **URL**: `/api/customers.php`
- **Roles Required**: `admin`, `manager`, `sales manager`

#### Request Body
```json
{
  "name": "Apex Innovations Ltd",
  "client_type": "monthly",
  "status": "active",
  "contact_person": "Sarah Connor",
  "phone": "9811223344",
  "email": "sarah@apexinno.com",
  "monthly_service_name": "App Backend Maintenance",
  "monthly_amount": 25000.00,
  "billing_day": 5,
  "due_days": 10,
  "auto_invoice": 1
}
```

#### Success Response (`HTTP 201 Created`)
```json
{
  "success": true,
  "message": "Customer created successfully",
  "id": 28,
  "name": "Apex Innovations Ltd"
}
```

#### Duplicate Conflict Response (`HTTP 409 Conflict`)
```json
{
  "success": false,
  "error": "A customer with email 'sarah@apexinno.com' already exists.",
  "message": "A customer with email 'sarah@apexinno.com' already exists.",
  "code": "DUPLICATE_RECORD",
  "errors": {
    "field": "email"
  }
}
```

---

### 3.4 Recurring Monthly Billing Overview (N+1 Query Optimized)
- **Method**: `GET`
- **URL**: `/api/customers.php?action=monthly_billing&month=2026-09`

#### Success Response (`HTTP 200 OK`)
```json
{
  "success": true,
  "message": "Monthly billing status loaded",
  "data": {
    "month": "2026-09",
    "clients": [
      {
        "id": 12,
        "name": "Acme Global Technologies",
        "monthly_amount": 35000.00,
        "billing_day": 1,
        "cycle_start": "2026-08-01",
        "cycle_end": "2026-09-01",
        "invoice_generated": true,
        "linked_invoice": {
          "id": 84,
          "invoice_no": "INV-10042",
          "total_amount": 35000.00,
          "status": "sent"
        }
      }
    ]
  }
}
```

---

## 4. Invoices (`/api/invoices.php`)

### 4.1 Create Itemized Invoice
- **Method**: `POST`
- **URL**: `/api/invoices.php`
- **Roles Required**: `admin`, `manager`, `finance manager`

#### Request Body
```json
{
  "customer_id": 12,
  "invoice_date": "2026-09-01",
  "due_date": "2026-09-11",
  "place_of_supply": "Uttarakhand",
  "discount": 1000.00,
  "notes": "Monthly software and API maintenance",
  "items": [
    {
      "name": "Android Application Maintenance",
      "hsn_sac": "998313",
      "qty": 1,
      "rate": 20000.00,
      "tax_rate": 18
    },
    {
      "name": "Server Monitoring & Security",
      "hsn_sac": "998313",
      "qty": 1,
      "rate": 16000.00,
      "tax_rate": 18
    }
  ]
}
```

#### Success Response (`HTTP 201 Created`)
```json
{
  "success": true,
  "message": "Invoice created successfully and posted to ledger",
  "id": 85,
  "invoice_no": "INV-10043",
  "total_amount": 41480.00
}
```

---

### 4.2 Invoice Detail with Line Items & Payment Allocations
- **Method**: `GET`
- **URL**: `/api/invoices.php?id=85`

#### Success Response (`HTTP 200 OK`)
```json
{
  "success": true,
  "message": "Invoice details loaded",
  "data": {
    "invoice": {
      "id": 85,
      "invoice_no": "INV-10043",
      "customer_id": 12,
      "customer_name": "Acme Global Technologies",
      "taxable_value": 36000.00,
      "tax_total": 6480.00,
      "discount": 1000.00,
      "total_amount": 41480.00,
      "amount_paid": 0.00,
      "amount_due": 41480.00,
      "status": "sent",
      "items": [
        {
          "id": 140,
          "name": "Android Application Maintenance",
          "qty": 1.00,
          "rate": 20000.00,
          "line_total": 20000.00
        }
      ],
      "payments": []
    }
  }
}
```

---

## 5. Payments & Receipts (`/api/payments.php`)

### 5.1 Record Client Payment
- **Method**: `POST`
- **URL**: `/api/payments.php`
- **Roles Required**: `admin`, `manager`, `finance manager`

#### Request Body
```json
{
  "customer_id": 12,
  "amount": 41480.00,
  "payment_date": "2026-09-05",
  "payment_method": "Bank Transfer (NEFT)",
  "transaction_id": "UTR882991024",
  "reference": "August Bill Clearance",
  "allocations": [
    {
      "invoice_id": 85,
      "amount": 41480.00
    }
  ]
}
```

#### Success Response (`HTTP 201 Created`)
```json
{
  "success": true,
  "message": "Payment recorded successfully and client ledger credited",
  "id": 34,
  "payment_no": "PAY-34",
  "amount": 41480.00
}
```

---

## 6. Projects & Work (`/api/work.php`)

### 6.1 List Projects
- **Method**: `GET`
- **URL**: `/api/work.php?type=projects&page=1&limit=20`

#### Success Response (`HTTP 200 OK`)
```json
{
  "success": true,
  "message": "Projects loaded",
  "data": {
    "items": [
      {
        "id": 5,
        "customer_id": 12,
        "customer_name": "Acme Global Technologies",
        "project_name": "Android CRM Mobile Client",
        "status": "in_progress",
        "agreed_amount": 150000.00,
        "start_date": "2026-08-01",
        "deadline": "2026-11-30"
      }
    ]
  },
  "pagination": {
    "total": 8,
    "page": 1,
    "limit": 20,
    "total_pages": 1,
    "has_more": false
  }
}
```

---

### 6.2 Assign Team Member to Project
- **Method**: `POST`
- **URL**: `/api/work.php?type=projects&action=assign_member`
- **Body**:
```json
{
  "project_id": 5,
  "employee_id": 9,
  "role": "Lead Mobile Engineer"
}
```

#### Success Response (`HTTP 200 OK`)
```json
{
  "success": true,
  "message": "Member assigned successfully",
  "project_id": 5,
  "employee_id": 9,
  "role": "Lead Mobile Engineer"
}
```

---

## 7. Credential Vault (`/api/vault.php`)

### 7.1 List Masked Credentials
- **Method**: `GET`
- **URL**: `/api/vault.php`
- **Protection**: Passwords are never sent in plain text in list views.

#### Success Response (`HTTP 200 OK`)
```json
{
  "success": true,
  "message": "Credentials loaded (masked)",
  "data": {
    "credentials": [
      {
        "id": 18,
        "customer_id": 12,
        "client_name": "Acme Global Technologies",
        "platform": "Google Play Console",
        "account_name": "Developer Console Access",
        "username_email": "play@acmeglobal.com",
        "two_factor_enabled": 1
      }
    ]
  }
}
```

---

### 7.2 Secure Password Reveal (Rate-Limited & Audited)
- **Method**: `POST`
- **URL**: `/api/vault.php?action=reveal`
- **Roles Required**: `admin`, `super admin`
- **Rate Limit**: Max 15 reveals per minute per admin.

#### Request Body
```json
{
  "credential_id": 18
}
```

#### Success Response (`HTTP 200 OK`)
```json
{
  "success": true,
  "message": "Credential revealed securely",
  "data": {
    "id": 18,
    "platform": "Google Play Console",
    "account": "Developer Console Access",
    "username": "play@acmeglobal.com",
    "password": "DecryptedPassword#2026",
    "two_factor": "Backup code: 8849-2011"
  }
}
```

---

## 8. Mobile Attendance (`/api/mobile_attendance.php`)

### 8.1 Geofenced Clock-In
- **Method**: `POST`
- **URL**: `/api/mobile_attendance.php?action=clock_in`
- **Rate Limit**: Max 6 requests per minute.

#### Request Body
```json
{
  "latitude": 29.220069,
  "longitude": 78.967784
}
```

#### Success Response (`HTTP 200 OK`)
```json
{
  "success": true,
  "message": "Clocked in successfully at 09:58 AM",
  "time": "09:58 AM",
  "status": "present",
  "late_minutes": 0,
  "distance_meters": 12.4
}
```

#### Geofence Violation Response (`HTTP 403 Forbidden`)
```json
{
  "success": false,
  "error": "You are 450m away from the office. Geofence radius is 200m.",
  "message": "You are 450m away from the office. Geofence radius is 200m.",
  "errors": {
    "distance_meters": 450,
    "allowed_radius": 200
  }
}
```

---

## 9. Notifications (`/api/notifications.php`)

### 9.1 Register FCM / Mobile Device Token
- **Method**: `POST`
- **URL**: `/api/notifications.php?action=register_device`

#### Request Body
```json
{
  "device_id": "android_hardware_uuid_9921",
  "platform": "android",
  "push_token": "fcm_token_string_from_firebase_messaging"
}
```

#### Success Response (`HTTP 200 OK`)
```json
{
  "success": true,
  "message": "Device registered successfully for push notifications"
}
```

---

### 9.2 Broadcast Notification (Admin / Manager)
- **Method**: `POST`
- **URL**: `/api/notifications.php?action=send`
- **Roles Required**: `admin`, `super admin`, `manager`

#### Request Body
```json
{
  "title": "System Update Notice",
  "message": "Android CRM v2.0 is now live for all team members.",
  "type": "system"
}
```

#### Success Response (`HTTP 200 OK`)
```json
{
  "success": true,
  "message": "Notification sent successfully"
}
```
