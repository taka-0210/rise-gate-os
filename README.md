# Rise Gate OS

改善を、文化に。

Rise Gate OS is a Company Operating System for accumulating project work, improvements, documents, and shared knowledge.

This repository contains the Laravel application and the living documentation for Rise Gate OS.

## Current Phase

Phase 1-1: Laravel foundation.

The goal of this phase is only to confirm that Laravel runs correctly as the base of Rise Gate OS. Business features such as authentication, database design implementation, projects, tasks, and improvements are not implemented yet.

## What Matters

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
- `docs/changelog.md`: Design decisions and changes

## Local Development

PHP is provided by XAMPP in this environment.

```powershell
C:\xampp\php\php.exe artisan serve
```

Then open:

```txt
http://127.0.0.1:8000
```

## Environment Note

Composer was bootstrapped with `composer.phar` during initial setup because Composer was not available on PATH.

XAMPP PHP required the `zip` extension for Composer package extraction. A backup was created at:

```txt
C:\xampp\php\php.ini.rise-gate-os.bak
```
