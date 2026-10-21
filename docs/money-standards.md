# Money-code standards

Approved by Rafael on 2026-10-21 ([decisions](decisions.md)). These rules are mandatory for every PR in the money area. Such PRs carry the `needs:money` label ([prompts/team.md](../prompts/team.md)).

**Scope.** The money area covers:
- the ledger;
- the split (franchise and OTA percentages);
- prices;
- payments and payment webhooks;
- payouts;
- Pix keys.

Each rule below is a short statement that a reviewer or test can check.

## 1. Representation
- M1. Money is never a float. No `float` or `double` type, `floatval()`, `round()` on floats, or float literal appears in any money path.
- M2. In PostgreSQL, money columns are integer cents as `BIGINT`, or `NUMERIC(14,2)` where a decimal is unavoidable. `REAL`, `DOUBLE PRECISION` and `MONEY` are not used.
- M3. In PHP, money is either `int` cents or an immutable Money value object: the brick/money library, or our own `final readonly` class. Every value carries its currency (BRL).
- M4. Split math uses integers only. Percentages are stored as integer basis points.
- M5. Every division has one explicit, documented rounding rule. Leftover cents go deterministically to casalivre, so the parts always sum exactly to the total.

## 2. Strict PHP
- P1. Every PHP file starts with `declare(strict_types=1);`.
- P2. PHPStan runs at max level (or Psalm at level 1) with zero errors. Money paths have no baseline entries.
- P3. Full types: every parameter, return and property has a native type. Money paths use no `mixed`, and no untyped arrays; use typed value objects or array shapes.
- P4. Value objects are `readonly`. Classes are `final` unless they are designed for extension.
- P5. Code style follows PSR-12/PER-CS, enforced by `php-cs-fixer` in CI.

## 3. Double-entry ledger
- L1. Every ledger transaction has at least two entries, and they sum to zero.
- L2. The ledger is append-only: no `UPDATE` or `DELETE` on ledger tables. A correction is a new transaction that reverses the original, and it references the original.
- L3. The database enforces these rules itself:
  - `CHECK` constraints (amount ≠ 0, valid account and currency);
  - a `DEFERRABLE INITIALLY DEFERRED` constraint trigger that rejects a transaction whose entries do not sum to zero at commit;
  - an application role with only `INSERT` and `SELECT` on the ledger tables.

## 4. Transactions and idempotency
- T1. Each money operation runs in exactly one DB transaction. That transaction is either `SERIALIZABLE` (with a retry on serialization failure) or takes explicit `SELECT ... FOR UPDATE` locks on the rows it reads to decide.
- T2. Each money operation carries an idempotency key, stored under a `UNIQUE` constraint. A retry, a replayed webhook or a double click returns the first result and never posts twice.

## 5. Tests
- X1. Money classes have 100% branch coverage.
- X2. Split tests are property-based: for random totals and percentages, the parts sum to the total and are never negative.
- X3. Rounding edge cases are tested, including 0, 1 cent, odd cents, the maximum value and every percentage combination in use.
- X4. A concurrency test fires the same operation in parallel with the same idempotency key and asserts that it is posted exactly once.

## 6. Audit
- A1. Every money action records who did it, when, why, and the before and after values.
- A2. Audit records hold no personal data (LGPD). Actors are referenced by ID only, and there are no names, documents or Pix key values in clear text.

## 7. Process
- R1. Money PRs are small, with one money operation or rule per PR.
- R2. A money PR does not merge while any `followup:` item on it is open.
- R3. A CI gate blocks the merge unless PHPStan (max), `php-cs-fixer` and the money-path coverage check all pass. The gate must exist before the first money PR.
- R4. Review: `approved:review` and `approved:db`, plus `approved:security` when the PR touches payments, webhooks or Pix keys. Teste runs the money test suite (§5).
