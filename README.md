# Rise Gate OS

改善を、文化に。

Rise Gate OS is a Company Operating System for accumulating project work, improvements, documents, and shared knowledge.

This repository contains the Laravel application and the living documentation for Rise Gate OS.

## Current Phase

Phase 1-6 completed: Operation preparation.

The Company OS foundation now works through Organization, Workspace, Client, Project, Project Members, and Improvement.

The next step is not to add more features immediately, but to operate Rise Gate OS development inside Rise Gate OS itself.

Tasks, Documents, Project Events, AI, and knowledge search are not implemented yet.

## Implemented Through Phase 1-6

- Laravel authentication without external starter kit
- User registration and login
- Organization creation during registration
- Workspace creation during registration
- Organization owner membership
- Workspace owner membership
- Workspace selection screen
- Current Workspace session handling
- Workspace middleware for protected screens
- Owner / Admin / Member / Viewer role foundation
- Project list
- Project creation
- Project detail
- Project Members
- Project role and permission separation
- Cross-workspace Project participation
- Project member-only access control
- Client list
- Client creation
- Client detail
- Project and Client association
- Internal Project creation without Client
- Workspace-scoped Client access control
- Improvement list
- Improvement creation
- Improvement detail
- Project-scoped Improvement access control
- Improvement visibility foundation for internal / project / client sharing

## Operation Start

Rise Gate OS now uses itself as the first operational project.

Development work should start from a Project and an Improvement inside Rise Gate OS.

Sample operation data can be created with:

```powershell
C:\xampp\php\php.exe artisan db:seed --class=RiseGateOsOperationSeeder
```

Seeded login user:

```txt
email: takami@rise-gate.local
password: password
```

## What Matters

- Client is a Company, not a contact person.
- Project is a shared place to move improvements forward with members and clients.
- Improvement is a company asset.
- Documents are vessels for knowledge.
- AI is a partner that helps use accumulated knowledge.
- Documentation grows with the code.

## Documentation

Design documents are managed separately from implementation code.

- `docs/README.md`: Documentation entrance
- `docs/philosophy.md`: Purpose and values
- `docs/architecture.md`: System structure
- `docs/database.md`: ER and table design
- `docs/roadmap.md`: Phase plan
- `docs/operation.md`: Operation rules for Project and Improvement
- `docs/development-standard.md`: RISE GATE全案件の開発・Git・手動デプロイ標準
- `docs/changelog.md`: Design decisions and changes

## Local Development

PHP is provided by XAMPP in this environment.

```powershell
C:\xampp\php\php.exe artisan serve --host=127.0.0.1 --port=8000
```

Then open:

```txt
http://127.0.0.1:8000
```

## Test

```powershell
C:\xampp\php\php.exe artisan test
```

## Environment Note

Composer was bootstrapped with `composer.phar` during initial setup because Composer was not available on PATH.

XAMPP PHP required the `zip` extension for Composer package extraction. A backup was created at:

```txt
C:\xampp\php\php.ini.rise-gate-os.bak
```
