# Remote Messenger command fixtures (Beacon spec 154 / bundle spec 008)

Wire contract of `POST /api/{project}/messenger/commands/claim` and
`POST /api/{project}/messenger/commands/{id}/result`, shared with Symfony Beacon
(`tests/Functional/Messenger/fixtures/messenger_commands/` there when mirrored).

| File | What |
|---|---|
| `signing.json` | Test worker key (not a real secret), HKDF parameters, derived subkeys (hex), canonical string and signature of the command in `claim_response.json` |
| `claim_request.json` | Claim body sent by the bundle (`Authorization: Bearer bwk_…`, `Content-Type: application/json`) |
| `claim_response.json` | `200` claim response with one signed `retry` command |
| `claim_response_discard.json` | `200` claim response with one signed `discard` command (same key) |
| `claim_response_empty.json` | `200` claim response without commands (`poll_after` back-pressure) |
| `result_done.json` / `result_failed.json` | Result bodies (`done` + outcome, `failed` + reason code + exception class) |

The project UUID of the DSN used by the contract test is `019fea2d-507b-7890-8b33-ca488db6f696`.
Timestamps are UTC `Y-m-d\TH:i:s\Z`; the signature covers them exactly as sent.
