# Security

Report a vulnerability privately to [Dmitry Vostrikov](mailto:dv.vostrikov@gmail.com).
Do not open a public issue for a problem that is still unfixed.

This repository does not use Dependabot. Continuous integration runs `composer audit` and reports abandoned packages without failing the build for abandonment alone.

The PostgreSQL credentials in `phpunit.xml` belong to the disposable local and CI database `tenant_test`. They are not a production secret. Do not point `PGSQL_*` at an application database.
