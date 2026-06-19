# Portfolio E2E test

Task 53 adds a read-only Laravel validation command for the portfolio-aware paper simulation flow:

```bash
php artisan cryptospot:portfolio-e2e-test
```

## What it checks

The report is organized into these sections:

1. Environment
2. Portfolio account
3. Scan-cycle expiry
4. Watchlist creation
5. Trade plan creation
6. Capital reservation
7. Trigger/entry conversion
8. Active INR P&L
9. Closed trade capital release
10. Expired plan capital release
11. Duplicate-symbol protection
12. Cooldown protection
13. Reconciliation
14. Dashboard readiness
15. Final status

The command validates that accounting totals match active reserved/deployed rows within ₹1, active same-symbol opportunities are not duplicated, expired untriggered reserved plans are released, closed portfolio trades are released, critical portfolio transactions are not duplicated, cooldown protection is honored after SL/trailing/win exits, and the portfolio dashboard route/view are registered.

## Safety

The command is validation/reporting only. It does **not**:

- run the scanner automatically
- create watchlist rows
- create trade plans
- trigger entries
- close simulated trades
- reserve or release capital
- reset or wipe data
- place real orders
- call private APIs
- use API keys

The only database write is a `system_health_logs` row with `service_name = portfolio_e2e_test`.

The `--reset-test-data` option is intentionally disabled to avoid accidental destructive cleanup on a production VPS.

## JSON output

Use JSON mode for automation:

```bash
php artisan cryptospot:portfolio-e2e-test --json
```

The output shape is:

```json
{
  "status": "pass|warning|fail",
  "sections": {},
  "errors": [],
  "warnings": [],
  "recommendations": []
}
```

## Health check query

After running the command, verify the health log with:

```sql
SELECT service_name, status, message, checked_at
FROM system_health_logs
WHERE service_name = 'portfolio_e2e_test'
ORDER BY checked_at DESC
LIMIT 5;
```
