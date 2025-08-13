# Chat API (Short Reference)

This document summarizes the HTTP API endpoints used by the chat app. All endpoints assume an authenticated session (Laravel web auth). Responses follow a JSON structure and may use a `data` wrapper with optional `meta`.

- Base URL: /
- Auth: Cookie-based session (Laravel). CSRF required for state-changing requests.
- Content types:
  - JSON for most requests: `application/json`
  - Multipart for file/attachments: `multipart/form-data`

## Bearer token (API) authentication
- API base: `/api/v1`
- Obtain a token via email/password login, then send it as `Authorization: Bearer <token>`.

1) Get a token

POST /api/v1/auth/login
Body (JSON):
{
  "email": "user@example.com",
  "password": "secret",
  "device_name": "my-client" // optional
}
Response (201):
{
  "access_token": "<token>",
  "token_type": "Bearer",
  "user": { "id": 1, "name": "...", "email": "..." }
}

Curl:
```bash
curl -X POST https://your-host/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@test-chat.dev","password":"administrator","device_name":"docs"}'
```

2) Use the token

Example: fetch conversations
```bash
curl https://your-host/api/v1/conversations \
  -H 'Authorization: Bearer <token>'
```

3) Check current user

GET /api/v1/auth/me
```bash
curl https://your-host/api/v1/auth/me \
  -H 'Authorization: Bearer <token>'
```

4) Logout (revoke token)

POST /api/v1/auth/logout
```bash
curl -X POST https://your-host/api/v1/auth/logout \
  -H 'Authorization: Bearer <token>'
```

## Conventions
- Pagination for messages uses `per_page` and `before_id` query params and returns `meta.has_more` and `meta.next_before_id`.
- Timestamp fields are ISO 8601 strings.
- IDs are integers.

## Endpoints

1) GET /conversations
- Returns the list of conversations for the current user.
- Response:
  {
    "data": [
      {
        "id": 1,
        "other_user": { "id": 2, "name": "..." },
        "last_message": { "id": 123, "body": "...", "created_at": "..." },
        "unread_count": 0,
        "updated_at": "..."
      }
    ]
  }

2) GET /conversations/{conversationId}/messages
- Query params: per_page (int), before_id (int)
- Returns messages in descending or paged order with pagination meta.
- Response:
  {
    "data": [
      { "id": 1001, "body": "...", "user_id": 1, "created_at": "...", "has_attachments": false }
    ],
    "meta": { "has_more": true, "next_before_id": 999 }
  }

3) POST /conversations/{conversationId}/messages
- Send a text-only message to a conversation.
- Body (JSON): { "body": "string" }
- Response: Message resource

4) POST /messages (multipart)
- Send a message with optional attachments (preferred for sending files).
- Body (multipart):
  - conversation_id (int) [required]
  - body (string) [optional]
  - attachments[] (file) [0..N]
- Response: Message resource

5) POST /conversations/{conversationId}/read
- Mark messages as read up to the given message.
- Body (JSON): { "message_id": int } (optional; if omitted, implementation may mark latest as read)
- Response: 204 No Content (or JSON with status)

6) PATCH /messages/{messageId}
- Edit a message body.
- Body (JSON): { "body": "string" }
- Response: Message resource

7) DELETE /messages/{messageId}
- Delete a message.
- Response: 204 No Content (or JSON with status)

8) POST /conversations/{conversationId}/attachments
- Upload a single attachment.
- Body (multipart): file (file)
- Response: Attachment resource or updated message payload

9) GET /users/search
- Query params: q (string)
- Returns user list matching the search query.
- Response:
  {
    "data": [ { "id": 2, "name": "...", "email": "..." } ]
  }

10) POST /contacts
- Create/add a contact for the current user (and/or start a direct conversation if not present).
- Body (JSON): { "contact_user_id": int }
- Response: Contact or Conversation summary

## Realtime (Broadcasting)

- POST /broadcasting/auth
  - Private/Presence channel authorization (handled by Laravel Echo/Pusher on the client). Requires CSRF.

- POST /pusher/user-auth
  - Pusher User Authentication endpoint used for the presence watchlist (online/offline). Requires CSRF.

## Notes
- Error handling follows standard Laravel JSON error format with appropriate HTTP status codes.
- Some list responses may omit the top-level data wrapper depending on resource/transformer; the client normalizes both `{ data: [...] }` and `[...]`.
