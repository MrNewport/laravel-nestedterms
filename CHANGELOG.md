# Changelog

## Unreleased

- Add reusable `ExactDecimal::normalize()` and opt-in configured `decimal:0` through `decimal:6` Tag types. Exact decimals retain their declared type and round-trip as canonical strings, with bounded input/precision, no float coercion or rounding, null preservation, and exact rescaling/type changes.
- Allow applications to disable package migration loading and supply their own constrained schema.
- Resolve all attachment inputs through the configured scoped Tag model, rejecting unavailable or ambiguous inputs before any pivot changes. Attachment helpers now use a single database transaction and explicit input limits; only an explicit empty list clears every attachment.
- Replace recursive descendant traversal with bounded breadth-first traversal. Add bounded ancestry, cycle detection, and subtree depth checks when reparenting. Hierarchy and scoped Term checks run independently of slug rendering.
- Add opt-in immutable stable Tag slugs, with short opaque generated identifiers. Preserve default hierarchical slugs and reject storage-length or collision-suffix overflow. Existing descendant slugs are not rewritten after ancestor changes.
- Preserve null typed values, including value-only updates, independently of zero, false, and empty strings.
- Add `modelsOfType(ModelClass::class)` for usable concrete inverse relations, including morph aliases and custom table names.

## 2.0.0 — 2026-09-12

Laravel 12/13 with bounded component dependencies. Fixed structured values being double-encoded, configurable table membership lookups and custom model relations. Parent traversal now rejects cycles and cross-term parents. Modernized the case-sensitive Pest bootstrap and added regression tests.
