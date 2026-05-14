# Grades API — Specification (v1)

A minimal, unauthenticated JSON:API used to manage student grades. Built on Laravel 13's native `JsonApiResource`.

## Base URL

```
{HOST}/api/v1
```

Example local: `http://127.0.0.1:8000/api/v1`

## Authentication

None. All endpoints are public.

## Conventions

- Resource responses follow the **JSON:API spec**:
  - `Content-Type: application/vnd.api+json`
  - Top-level `data` wraps the resource(s).
  - Each resource has `type`, `id`, and `attributes`.
- Non-resource responses (errors, "operation succeeded" acks) use a small envelope: `{ status, message }`.
- IDs are **UUIDs**.
- Validation errors are Laravel-style: `{ message, errors: { field: [..] } }`.

## Resource: Grade

| Field               | Type                | Notes                                              |
| ------------------- | ------------------- | -------------------------------------------------- |
| `id`                | `string` (uuid)     | Primary key.                                       |
| `name`              | `string`            | Student name. 1–255 chars.                         |
| `subject`           | `string`            | Subject name. 1–255 chars.                         |
| `score`             | `integer`           | 0–100.                                             |
| `horizon_processed` | `boolean`           | Toggled by a queued job after create.              |
| `ago`               | `string` \| `null`  | e.g. `"3 minutes ago"`. Set by scheduler.          |
| `created_at`        | `string` (ISO 8601) |                                                    |
| `updated_at`        | `string` (ISO 8601) |                                                    |

> `horizon_processed` and `ago` are populated asynchronously. Treat them as read-only on the frontend; do not send them in requests.

## Endpoints

### 1. List grades — `GET /grades`

Paginated list (latest first).

**Query params** (all optional):

| Param                       | Type    | Default | Notes                                                                                  |
| --------------------------- | ------- | ------- | -------------------------------------------------------------------------------------- |
| `page[number]`              | int     | `1`     | Page number.                                                                           |
| `page[size]`                | int     | `25`    | Page size.                                                                             |
| `fields[grades]`            | string  | —       | JSON:API sparse fieldset, e.g. `name,score`.                                           |
| `filter[name]`              | string  | —       | Partial (LIKE) match on `name`.                                                        |
| `filter[subject]`           | string  | —       | Exact match on `subject`.                                                              |
| `filter[min_score]`         | int     | —       | `score >= value`.                                                                      |
| `filter[max_score]`         | int     | —       | `score <= value`.                                                                      |
| `filter[horizon_processed]` | boolean | —       | Exact match (`true`/`false`).                                                          |
| `sort`                      | string  | `-created_at` | Comma-separated. Prefix with `-` for descending. Allowed: `score`, `name`, `subject`, `created_at`. |

**Examples**

```
GET /api/v1/grades?page[number]=1&page[size]=10
GET /api/v1/grades?fields[grades]=name,score
GET /api/v1/grades?filter[subject]=Math&filter[min_score]=70
GET /api/v1/grades?sort=-score
GET /api/v1/grades?filter[subject]=Math&sort=-score,name&page[size]=5
```

> Invalid filter or sort keys return **400 Bad Request** (Spatie Query Builder rejects unknown keys).

**Response — 200 OK**

```json
{
  "data": [
    {
      "type": "grades",
      "id": "9c3a2b4f-7a0e-4f7e-9e8e-2c8e2e6f1234",
      "attributes": {
        "name": "Ahmed",
        "subject": "Math",
        "score": 90,
        "horizon_processed": true,
        "ago": "10 minutes ago",
        "created_at": "2026-05-11T16:20:00.000000Z",
        "updated_at": "2026-05-11T16:20:01.000000Z"
      }
    }
  ],
  "links": {
    "first": "http://127.0.0.1:8000/api/v1/grades?page%5Bnumber%5D=1",
    "last":  "http://127.0.0.1:8000/api/v1/grades?page%5Bnumber%5D=1",
    "prev":  null,
    "next":  null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "path": "http://127.0.0.1:8000/api/v1/grades",
    "per_page": 10,
    "to": 1,
    "total": 1
  }
}
```

---

### 2. Get grade by id — `GET /grades/{id}`

**Path params**

| Param | Type            |
| ----- | --------------- |
| `id`  | `string` (uuid) |

**Optional**: sparse fieldsets via `?fields[grades]=name,score`.

**Response — 200 OK**

```json
{
  "data": {
    "type": "grades",
    "id": "9c3a2b4f-7a0e-4f7e-9e8e-2c8e2e6f1234",
    "attributes": {
      "name": "Ahmed",
      "subject": "Math",
      "score": 90,
      "horizon_processed": true,
      "ago": "10 minutes ago",
      "created_at": "2026-05-11T16:20:00.000000Z",
      "updated_at": "2026-05-11T16:20:01.000000Z"
    }
  }
}
```

**Response — 404 Not Found**

```json
{ "status": 404, "message": "Grade not found" }
```

---

### 3. Create grade — `POST /grades`

**Request body**

| Field     | Type     | Required | Validation     |
| --------- | -------- | -------- | -------------- |
| `name`    | string   | yes      | 1–255 chars    |
| `subject` | string   | yes      | 1–255 chars    |
| `score`   | integer  | yes      | 0–100          |

**Example**

```json
{ "name": "Ahmed", "subject": "Math", "score": 90 }
```

**Response — 201 Created**

```json
{
  "data": {
    "type": "grades",
    "id": "9c3a2b4f-7a0e-4f7e-9e8e-2c8e2e6f1234",
    "attributes": {
      "name": "Ahmed",
      "subject": "Math",
      "score": 90,
      "horizon_processed": false,
      "ago": null,
      "created_at": "2026-05-11T16:20:00.000000Z",
      "updated_at": "2026-05-11T16:20:00.000000Z"
    }
  }
}
```

**Response — 422 Unprocessable Entity** (Laravel validation shape)

```json
{
  "message": "The score field must be between 0 and 100.",
  "errors": {
    "score": ["The score field must be between 0 and 100."]
  }
}
```

---

### 4. Delete grade — `DELETE /grades/{id}`

**Path params**

| Param | Type            |
| ----- | --------------- |
| `id`  | `string` (uuid) |

**Response — 200 OK**

```json
{ "status": 200, "message": "Grade Deleted Successfully" }
```

**Response — 404 Not Found**

```json
{ "status": 404, "message": "Grade not found" }
```

---

## Generic error envelope (4xx/5xx for non-validation errors)

```json
{ "status": 500, "message": "Grade Creation Error" }
```

## TypeScript types

```ts
export type UUID = string;

export interface GradeAttributes {
  name: string;
  subject: string;
  score: number;             // 0–100
  horizon_processed: boolean;
  ago: string | null;
  created_at: string;        // ISO 8601
  updated_at: string;        // ISO 8601
}

export interface GradeResource {
  type: "grades";
  id: UUID;
  attributes: Partial<GradeAttributes> & Pick<GradeAttributes, "name"> extends infer _ ? GradeAttributes : never;
  // For sparse fieldsets, attributes may be a subset — use Partial<GradeAttributes> if you query fields[grades].
}

export interface JsonApiSingle<T> {
  data: T;
}

export interface PaginationLinks {
  first: string | null;
  last: string | null;
  prev: string | null;
  next: string | null;
}

export interface PaginationMeta {
  current_page: number;
  from: number | null;
  last_page: number;
  path: string;
  per_page: number;
  to: number | null;
  total: number;
}

export interface JsonApiCollection<T> {
  data: T[];
  links: PaginationLinks;
  meta: PaginationMeta;
}

export interface ApiAckEnvelope {
  status: number;
  message?: string;
}

export interface ValidationErrorResponse {
  message: string;
  errors: Record<string, string[]>;
}

// Request payloads
export interface CreateGradeRequest {
  name: string;
  subject: string;
  score: number;
}

// Endpoint return types
export type ListGradesResponse  = JsonApiCollection<GradeResource>;
export type ShowGradeResponse   = JsonApiSingle<GradeResource>;
export type CreateGradeResponse = JsonApiSingle<GradeResource>;
export type DeleteGradeResponse = ApiAckEnvelope;
```

## Endpoint reference (cheat sheet)

| Method   | Path                  | Body                  | Success | Errors             | Response shape           |
| -------- | --------------------- | --------------------- | ------- | ------------------ | ------------------------ |
| `GET`    | `/api/v1/grades`      | —                     | 200     | 400, 500           | `JsonApiCollection`      |
| `GET`    | `/api/v1/grades/{id}` | —                     | 200     | 404, 500           | `JsonApiSingle`          |
| `POST`   | `/api/v1/grades`      | `CreateGradeRequest`  | 201     | 422, 500           | `JsonApiSingle`          |
| `DELETE` | `/api/v1/grades/{id}` | —                     | 200     | 404, 500           | `ApiAckEnvelope`         |

## Notes for the frontend

- `Content-Type: application/vnd.api+json` is set on resource responses (the four grade endpoints). It's safe to parse as JSON.
- `horizon_processed` flips `false → true` shortly after a grade is created (queued job runs via Horizon). The UI can refresh to reflect this.
- `ago` is refreshed every minute by a scheduler on the **last 5 grades only**. Older entries keep their last-known value (or `null`).
- The server caches GET responses for 60s, but **invalidates** them on every `POST` / `DELETE`, so the next list reflects the change.
- **Sparse fieldsets** (`?fields[grades]=name,score`) are supported by Laravel's JSON:API resource and will return a subset of attributes — use `Partial<GradeAttributes>` on the client when you opt in.
