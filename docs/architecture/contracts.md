# Контракты, расширения и recovery

Semantic/wire/SPI v1 зафиксированы P1.1 в manifests ниже. Runtime и адаптеры реализованы в `packages/`; актуальные способы подключения описаны в [integration guide](../integration.md), а PHP API каждого пакета — в его `resources/contracts/php-api.json`. Исторические этапы квалификации ниже не являются отчётом о текущем запуске тестов. Контракт не предоставляет полномочия произвольному коду хоста: trust boundary — composition root и входы извне процесса.

Необязательный `tenant-panels` добавляет допуск в панели через существующий runtime и host-owned проекцию scope. Он не меняет wire v1, не добавляет UI framework в ядро и не становится вторым владельцем tenant-контекста.

Дополнительный профиль [laravel-scoped](laravel-profiles.md) оставляет Eloquent connections и SQL-изоляцию приложению, предоставляя runtime и выбранные cache/session/storage facilities без database route. Он не переименовывает старый PG profile и не изменяет его гарантии или frozen corpus.

## Минимальный API

| Поверхность | Вход / результат | Обязательная семантика |
|---|---|---|
| `Tenant::current()` | `ContextState`: Missing, Central или Active с immutable IsolationContext | Никогда не трактует null как wildcard. Single — Active с single profile, не Central; во время переключения бросает ContextTransitionInProgress |
| `Tenant::enter(selection, purpose)` | Opaque `FrameToken` после trusted resolve | Selector — namespace/id/profile либо явный central/single intent; run не принимает caller DSN и не доверяет готовому DTO. Каждый enter делает resolve/admission, даже same identity; оптимизация facilities не пропускает freshness |
| `Tenant::run(selection, purpose, callback)` | Результат callback; гарантированный restore в finally | Предпочтительная application API. Callback вызывается только после успешной активации. При failed restore результат не выдаётся как успех |
| `Tenant::restore(frameToken)` | Restored или typed failure | Только верхний token этого runtime/lane/generation, одно завершение; проверяет previous frame, handles, product hooks. Фатальный restore gap блокирует следующий enter |
| `ContextResolver` SPI | Selection/purpose/durable expected binding → `ResolvedIsolation` или reason-coded refusal | Host origin, allowed namespaces/profiles, resource availability/incarnation и маршрут из authoritative catalog; authentication/actor verdict — другой host port |
| `Facility` SPI | prepare/activate/restore/verify с opaque frame-local saved state | Открытые namespaced capability/implementation IDs; встроенные DB/cache/session/storage/queue и product-scope hook — первый qualified набор. Typed host registration и dependency/order declarations; нет service-locator bag и callbacks из payload. Правила композиции — ниже |
| Durable ref codec | Scalar `tenant.context/1` → validated reference, не active context | Размер/shape/version allowlist до lookup; unknown namespace/profile/version/required extension — reject. Только установленный host decoder знает handler extension |

`IsolationIdentity = {namespace, opaque id}`; `IsolationProfile = {id, version, required facilities}`; `ResourceBinding = {resourceRef, incarnation, routeRevision, facetBindings}`. Namespace+id не равны имени БД или UUID account. Host выбирает нормализацию opaque ID; wire не допускает неоднозначного int/string сравнения. DB names создаёт только PG owner с безопасной allowlist/quoting, а не конкатенацией пользовательского opaque ID.

Pure PHP ядро хранит только execution stack; authoritative resource catalog — у выбранного host/resource owner. Оно не создаёт реестр всех компаний/аккаунтов. Credentials не входят в public refs, logs или receipts. Ошибки reason-coded: missing, denied, unavailable, stale_incarnation, unsupported_profile/version, selection_lost, switch_failed, restore_failed, runtime_poisoned, transition_in_progress, idempotency_conflict, operation_unknown.

Публичны только отмеченные Api/Spi Contracts/Data/Enums/Events/Exceptions; persistence/ORM и factories реализации — Internal. `Tenant` — final readonly facade API над публичным runtime SPI с внутренней реализацией по умолчанию; Laravel facade/provider живёт в `tenant-laravel`. Runtime SPI позволяет явное host binding/decorator, не требует наследования Internal executor. Это уточнение Engineering-Standards: plain PHP consumer не требует Laravel facade. Маркеры API не требуют обязательного общего Common; tooling задаётся в P1.

## Frame state machine и lifetime

Состояния: idle/active → preparing → activating → active → restoring → previous; любая невосстановимая ветвь → poisoned. Это логические состояния runtime, не persistent business FSM.

1. Захватить execution-lane guard, проверить token/lane и запрет переключения при открытой transaction/cursor/stream на переключаемом binding. Центральная metadata transaction допустима лишь если facility доказывает, что её соединение не затрагивается. Не переносить активную transaction между tenant DB.
2. Сохранить current context и saved state всех enabled facilities. Валидировать plan facilities до первого изменения. Для entry из Missing baseline остаётся Missing; явная Central — отдельный frame с центральными bindings. Worker boundary всегда устанавливает clean Central.
3. Resolver перечитывает binding и availability. Product hook читает данные только через уже выбранный resource facet; полный user command ещё не выполняется. При необходимости admission на intent проходит и до lookup, и у owning handler перед effect.
4. Каждый activation участник попадает в rollback journal до изменения. Не публиковать current Active, пока required facilities и product scope не подтверждены. Product hooks получают phase-bound candidate context, не обходят current API.
5. При switch invalidate старые scoped instances; восстанавливаемому frame создаются новые instances. Полученные внутри callback services/handles не выдаются наружу как безопасные долгоживущие объекты. Handle содержит generation и ref; каждое IO сверяет актуальность.
6. Ошибка activation → reverse rollback всех начатых, включая частично упавшую; rollback errors агрегируются, остальные reverts всё равно выполняются. Ошибка callback сохраняется вместе с cleanup errors. Даже при успешном cleanup нельзя молча выдавать результат callback, который упал.
7. Restore предыдущего binding выполняет fresh routing/incarnation lookup: если ресурс исчез или incarnation сменился, runtime poisoned. Автоматическое «продолжить centrally» запрещено. Исходная ошибка и restore reason сохраняются без credentials.
8. Supervisor на queue/FPM boundary завершает poisoned приложение. Queue worker не берёт следующий job; release/retry решает transport policy отдельно от business receipt. Деструктор token не является гарантией cleanup; забытый token на boundary — fatal leak.

Запрещены concurrent/interleaved enter на глобальных Laravel facilities; один runtime на lane недостаточен для поддержки Fibers, пока resource primitives process-global. Octane и coroutine профили не заявляются. Отключённая facility не даёт гарантии изоляции соответствующего IO; требующая её application операция отказывает при capability negotiation.

## Durable references и очереди

Wire envelope `tenant.context/1`: явный `mode` (tenant/central/single), namespace/isolationId, profileId/profileVersion, resourceRef/incarnation, routing hint revision, extension envelopes `{type, version, scalar refs}`. Extension payload bounded, allowlisted по handler. Поля actor/authority/operation принадлежат owning handler envelope; Tenant не превращает их в permission.

Dispatch требует явного режима. `dispatchTenant(ref,...)` фиксирует scalar target/ref/incarnation при вызове API; при enqueue проверяется тот же frozen intent, `dispatchCentral(...)` не наследует ambient stamp. PendingDispatch, который попал за lifetime callback, не должен переполучить чужой ambient context: adapter фиксирует intent до deferred destruction либо отказывает. Central transport storage и central execution — разные bindings. DB jobs/failed jobs и identity/billing metadata всегда на named central connection.

Processing: boundary clean Central → decode/producer trust → fresh resolution → match original resource incarnation → activate → decode/validate required product extension → fresh handler admission → effect/receipt → cleanup. Не десериализовать Eloquent model до выбора безопасного ресурса; v1 durable payload использует только scalar DTO. System tasks получают явный system-purpose с проверенным target; maintenance purpose не зависит от paid status, но требует собственного разрешения.

Sync nested jobs восстанавливают каждый внешний frame; async job начинается/заканчивается Central. Completion привязан к framework job object/attempt + верхнему token; duplicate/foreign/released/failed callbacks не снимают чужой frame. Ack/delivery/handler return не подтверждают завершение business operation. Несовместимые pending messages при source switch в dev очищаются/пересоздаются согласованной cohort, старый `corex_tenant` decoder не создаётся.

Resource ID сохраняется при обычном reconnect/move лишь с проверенным routeRevision; incarnation меняется при замене/restore/clone. Clone получает новый resource identity и incarnation. Restore того же logical ID получает новую incarnation до открытия admission; старые jobs/operations запрещены. Намеренный replay — отдельная privileged recovery операция с mapping и свежим admission, без молчаливого rebind.

## Product scopes и пример FlexCRM

Product context оборачивает isolation context typed refs, например `WorkspaceSelection`, `OwnCompanySelection`, `CitySelection`. Плагины не создают второй executor: участвуют в frame hook и восстанавливают собственное scoped state в том же journal. Порядок: resource resolution/activation → relation validation → product scope publication → handler admission/effect. Failures входят в общий rollback.

Изменение company/city не переключает DB без explicit isolation profile. Каждая relation у своего writer; версия/lock относится к owning product mutation. Проверку A↔W↔C нельзя заменить тремя независимыми FK. Для addressed mutation relation/membership проверяются повторно в owning transaction с qualified locks/revision fence; cross-DB authority требует собственного freshness protocol, а не заявления об атомарности Tenant.

Старые scope различия и адресная приёмка — [relationships](relationships.md). Целевой профиль использует explicit `Selected(W)`, `AccountWide(capability)`, `Missing`; NULL в таблице остаётся продуктовой семантикой. Смена с исторического legacy/tenancy/CRM scope — осознанный breaking source change, не автоматическая замена global scope. OwnCompany ref, CRM counterparty company input, Actor и Payer имеют разные типы/namespace.

Initial UI default selection — отдельная операция. Если command уже адресована W/C, исчезновение/revoke selection возвращает selection_lost/denied; нельзя молча перейти к default или account-wide. Cardinality company↔workspace/city и employment policy определит продукт; ядро не зависит от решения.

## Optional lifecycle и физическая authority

Публичные responsibilities: `claim/provision`, `queryOperation`, `reconcile`, `requestExport`, `queryExport`, `requestDelete`, `cancelIntent`, `restore/clone admission`. Это не remote API и не обязательная установка. Restore/clone первоначально controlled operator runbook с теми же checks; неподдержанный автоматический вызов отказывает.

Один optional owner хранит resource catalog, template snapshots, immutable allocation/signup binding, operation key+canonical digest, status/observed effect/receipt, archive manifest, tombstone/incarnation и operation attempts. CoreX account/business owner хранит account slug/owner/product status/trial/grace/products/domains, identity/membership/workspaces и ссылку на resource. `root_accounts` нельзя переносить целиком с коммерческой FSM. Полный schema ownership manifest проектируется до кода, ровно один schema writer на каждый артефакт.

Canonical intent включает schemaVersion, operationKind, host namespace/product intent ref, target resource+incarnation, template ID/version+manifest hash, cluster binding и значимые parameters. Actor не меняет исходную identity операции при replay; fresh admission обязателен. Same key + другой semantic intent = conflict, включая иные cluster/template. Ключи/tombstones не переиспользуются после soft delete или restore. Retention бизнес-истории задаётся host; технический минимум — сохранение replay horizon и запрет при недостаточной истории.

PostgreSQL CREATE/DROP выполняются вне metadata transaction: [CREATE DATABASE](https://www.postgresql.org/docs/16/sql-createdatabase.html), [DROP DATABASE](https://www.postgresql.org/docs/16/sql-dropdatabase.html). Receipt-state FSM: accepted/running/unknown/succeeded/failed/cancelled; resource availability (allocating/ready/maintenance/deleting/deleted/unknown) отдельно от trial/grace/suspension. Error на transport/timeout после отправки effect не означает failed.

Протокол: claim intent+freeze target+attempt generation в короткой metadata transaction → DDL/export вне неё → observe exact target → CAS по attempt generation/incarnation → durable receipt/outbox → publish. При неизвестном исходе query/reconcile той же operation. Нет второго SQL из host «на всякий случай».

Lease generation защищает metadata commit, но сам по себе **не fence для PostgreSQL DDL**. Перед effect owner удерживает resource-specific advisory lock на той же PG session, которая исполняет DDL; запрет второго session takeover до наблюдения завершения старого backend. При потере session исход unknown; новый attempt проверяет catalog/physical effect и ownership marker, не повторяет blind CREATE/DROP. Имена/DB OID сами по себе недостаточны: catalog+incarnation/marker связываются с frozen target. Если нельзя доказать отсутствие старого in-flight DDL, resource quarantined и admission закрыт, требуется operator reconciliation. В P1/Postgres qualification требуется executable proof на kill-connection/reconnect boundaries; недоказуемая ветвь остаётся fail-closed, а не «exactly once».

Export фиксирует original operation/exportId и archive destination, проверяет содержимое+manifest+resource/incarnation/hash/retention; отсутствие writer/verifier отказывает до destructive действий. Успешный DROP с потерянным root commit восстанавливает original receipt/tombstone через наблюдение; событие deleted публикуется только после durable outcome. Cancellation закрывает admission и отделяет grant cancellation от уже отправленного DDL: late CREATE reconciled к тому же original resource; право повторной активации — у host.

ProductCoordinator может связывать occurrence→resource operation receipt и grants, но не ведёт вторую authoritative resource FSM. CoreX schema migrator остаётся orchestrator schema-set execution; Tenant поставляет собственный catalog schema set, CoreX — свои account/product schema sets. Для standalone PG owner — свой публичный migration entry point; два runner на один schema set одновременно запрещены.

При восстановлении всего control-plane backup, а не только tenant DB, epoch не должен восстановиться вместе с устаревшим catalog. Единственный resource owner ведёт recovery generation вне восстанавливаемого набора (durable operator/deployment control record), а перед reopen регистрирует новые incarnation для затронутых ресурсов. Пока reconciliation generation не завершён и старые in-flight backends не исключены, admission и workers закрыты. Это техническая часть того же owner, не второй каталог решений. Без такого anchor автоматическое replay после полного restore не поддерживается.

`profileId`/`routeRevision` в wire — ожидаемое ограничение, а не разрешение caller выбрать adapter или DSN. Authoritative resolver выбирает binding по host policy; несовпадение profile/version/facets отказывает. Facility namespaces берутся из единого resolved descriptor; plugins не выводят Redis/storage prefixes заново из неподтверждённого company/city ID.

## Laravel boundary qualification

Queue enter hook обязан выполняться до десериализации команды/model hydration, а cleanup — после всех framework completion/failure callbacks, которым нужен job scope. Точный порядок `JobProcessing`/`fire`/`JobAttempted` и released/failed путей проверяется на **обеих locked lanes Laravel 12/13**, не выводится из названий событий. V1 application payload scalar-only; model hydration fixture дополнительно доказывает, что сторонний framework callback не читает чужую DB. Job middleware сам по себе недостаточен для pre-hydration routing.

HTTP route resolution по доверенному host/domain выполняется до tenant-backed session IO; это выбор маршрута, не разрешение protected action. Session/auth middleware завершаются до product relation/admission и effect. Tenant frame остаётся жив до session save и требующих его terminate callbacks; cleanup после них. Central authentication connection не переключается. Такой порядок снимает цикл «нужна session для admission, нужен context для session» без выдачи прав по host selector.

Missing profile устанавливает отказной tenant IO binding: unqualified default DB/storage/dispatch не падает на central connection. Явный central control-plane API использует отдельные named bindings. `Config`, Laravel Context, CoreX projections и facades — делегаты единственного runtime, не дополнительные current setters. Boot проверяет ровно один runtime binding и совместимость SPI versions до трафика.

Poison guard действует и внутри текущего callback после пойманной ошибки. Queue loop завершается до sentinel job. Для первого FPM qualification профиль требует `pm.max_requests=1` (child не обслуживает следующий request) и закрытия request без новых tenant effects; это явная цена safety в v1. Снятие этого ограничения требует проверенного host primitive завершения/замены child после poison. `exit` в PHP request сам по себе не объявляется доказательством смерти FPM child.

PG binding proof выдаётся/verifies публичным port единственного resource owner; Laravel facility не читает private catalog/marker таблицы соседнего пакета напрямую. После clone owner записывает новую resourceRef/incarnation до ready — шаблонный или скопированный stamp не разрешает доступ. Template hash/signature проверяет qualified artifact; instance marker подтверждает конкретный созданный ресурс. Runtime-only host без `tenant-postgres` должен предоставить равноценный trusted binding verifier для заявленного DB profile; отсутствие verifier не превращается в «проверить только database name».


## Vaulter scalar binding

`TenantResolver.current()` возвращает канонический непустой scalar из пары namespace+normalized opaque ID: versioned length-prefixed/base64url encoding, инъективное на принятом пространстве identities; конкретный формат/ограничение длины фиксируется consumer fixture до записи. Срезать namespace, хешировать без collision protocol или молча обрезать значение нельзя. Если installed Vaulter/schema не принимает этот encoding, первый binding ограничивается одним фиксированным namespace и boot отказывает от второго; это отдельный explicit profile, не общий multi-namespace режим. `ResolvesOwnerTenant` использует ту же функцию. Incarnation отдельно проверяется runtime до Vaulter validator, не подменяет business tenant identity.

В multi-resource профиле Vaulter tenancy enabled+strict обязательны. Missing/Central → ScopeRequired/Denied до Vaulter operation, а не `null`, отключение strict или bypass. Single тоже имеет стабильный непустой scalar. Центральные административные Vaulter операции допускаются только отдельным explicit privileged API, не через этот resolver. TC-25 включает два namespace с одинаковым id, Missing/Central, owner equality и длину/тип scalar; TC-21 проверяет реальный IO после проекции.

Actual Drive producers входят в consumer contract. В owning Vaulter `packages/drive/src/Actions/Drives/DeleteDriveAction.php::execute()` регистрирует purge через afterCommit; `packages/drive/src/Jobs/PurgeDriveJob.php::handle()` сам публикует continuation; `packages/drive/src/Content/ScanWorkReconciler.php::afterCommit()/publish()` откладывает scan publication. Один ручной `dispatchTenant` в тесте не проверяет эти пути. P1.1 фиксирует neutral public host-dispatch seam либо квалифицируемый framework hook и момент capture **до** deferred callback; P5.2 подключает реальные producers и continuation. Выбор seam/signatures принадлежит freeze и последующей детализации по owning source, отдельный новый пакет не предписан. Bridge не импортирует private Drive classes; neutral core/drive не зависят на Tenant.

Frozen scalar intent сохраняет original resourceRef/incarnation/mode и применимые handler refs до afterCommit; повторная публикация/continuation не получает новый ambient target. Fixture A schedules → frame A закрыт/B active → enqueue обязана сохранить A либо выдать типизированный отказ; B никогда не становится target. Реальные worker tests включают purge/scan/continuation, fresh admission перед validator и одинаковый driveId в A/B; уникальные queue locks тоже не должны подавлять работу другого ресурса. P1 задаёт expected outcomes, P5.2 доказывает их на actual callers и IO. Изменения neutral producer seam выполняются только в owning Vaulter в P5.2 под consumer authority.

## Baseline и отключённые facilities

Central frame использует явно заданные named control connections. Default tenant IO в Central остаётся отказным; central operation обязана назвать свой resource binding. Payload без `tenant.context/1` отвергается до обработки, кроме явной allowlist центральных job classes с known trusted producer и отдельным central dispatch. Для vendor jobs allowlist и actual hook fixture обязательны; отсутствие stamp не означает automatic Central.

Multi-resource boot сверяет framework stores: каждая используемая DB/cache/session/storage facility либо включена, либо явно declared shared с owner и permitted purpose. Случайно выключенная facility при настроенном framework store — configuration error. Shared cache не становится общей company visibility без product policy.

Пока frame activating, public `current()`/CoreX projections выбрасывают typed `ContextTransitionInProgress` (reason transition_in_progress), а unqualified IO отказывает. Product validation использует только phase-bound candidate read capability после resource activation; old current не применяется к новой DB. Это typed refusal в transient lifecycle state, не четвёртое значение ContextState и не selectable mode.

Single durable ref также обязательно содержит resourceRef и incarnation, включая synthetic host binding для php-only без IO. При restore внешний frame проверяется по существованию/identity/incarnation, maintenance admission отдельно: paused ordinary effects могут быть denied без ложного stale_incarnation; физически удалённый/заменённый ресурс отравляет frame. DROP при активном frame и последующий IO проверяются отдельной failure fixture. Runtime-only PG host отвечает за TC-11/22 и recovery anchor даже без tenant-postgres; иначе recovery capability не заявляется.

DDL advisory locks берутся всеми attempts на одной явно закреплённой maintenance DB одного PG cluster, тем же session/backend что исполняет DDL; pooler transaction mode не допускается для этого protocol без отдельного proof. Cross-cluster operation имеет frozen cluster binding. P4 composition tests живут в отдельном test host, не добавляют sibling require-dev tenant-postgres→tenant-laravel.

Dispatch фиксирует target/ref/incarnation и mode при вызове explicit dispatch API. При фактическом enqueue/afterCommit adapter повторно проверяет допустимость frozen target, не считывает новый ambient context. После закрытия исходного frame допустим только скалярный frozen ref; unavailable/revoked intent даёт отказ согласно owning purpose, а не перенаправление.


Runtime-only CoreX остаётся единственным physical owner: в его technical catalog/baseline хранится новое opaque resource_incarnation, а в физическом ресурсе — owner-controlled instance marker. CoreX публичный binding verifier сверяет expected resourceRef/incarnation с catalog и actual marker до IO; новый scalar ref выпускается только из verified descriptor. DataScope restoreEpoch/connectionIdentity и имя БД сами по себе не incarnation. Fresh provision/test seed создаёт marker до ready; для restore/clone CoreX регистрирует новую incarnation и recovery generation из внешнего recovery anchor до reopen. Anchor вне catalog backup; без него full-control-plane restore unsupported/fail-closed, workers не открываются. P1.1 фиксирует схему/port, P5.1 реализует catalog/marker/verifier/ref emission и stale/mismatch tests под TENANT-CONSUMERS. Это доработка текущего owner, не перенос ownership: P5.4 переводит свежий baseline и единственный writer в tenant-postgres, удаляя прежнюю регистрацию.

## Композиция плагинов, замены и события

Плагин — типизированные декларации host composition root, не новый пакет/loader и не универсальный framework. Для plain PHP достаточно constructor composition; Laravel provider переводит typed config в ту же модель. Kernel не сканирует Composer, не знает Chatom/AzGuard/CRM и не содержит контейнер. Имена SPI/signatures зафиксированы P1.1 в `contracts/api.v1.json`.

1. **Регистрация и freeze.** До первого resolve/tenant IO host собирает immutable composition manifest: SPI versions, profiles, namespaced capability и implementation IDs, factories/decorators и required/before/after edges. Декларации не читают tenant state и не исполняют IO. Идентификаторы facilities открыты для host extensions; поддержка нового ID требует квалифицированной реализации и profile, а не изменения enum ядра. Неизвестная required capability отказывает до activation. Wire содержит лишь refs к разрешённому profile/extension, никогда class-string/factory.
2. **Конфликты и порядок.** Slot определяет одну изменяемую facility/binding, например DB connection или session store. Две реализации одного exclusive slot — boot error, кроме явного host replacement с именем заменяемой реализации и совместимым SPI/capabilities. Decoration оборачивает одного delegate; priority и порядок service providers не выбирают победителя. Missing required dependency, cycle, несовместимая версия или late registration — ошибка до первого изменения ресурсов. Topological order стабилен независимо от registration order; равноправные узлы упорядочиваются namespaced ID. Cleanup следует обратному фактическому started journal, включая частично упавший узел. Зависимости callback/resource стадии не могут переупорядочить resource-before-product validation.
3. **Lifetime.** Manifest/factories можно переиспользовать в процессе; mutable saved state принадлежит конкретному frame и участнику. Один `$original` в shared bootstrapper не может быть overwritten вложенным frame. Реализация либо stateless с opaque frame state, либо получает отдельный participant instance на frame. Nested same-resource/product-only frames проходят тот же протокол. Plugins получают phase-bound capabilities, не сервис с неограниченным `setCurrent()`. Participant не зависит транзитивно от runtime/resolver, создавая цикл construction; повторные enter/restore из prepare/activate/restore/verify запрещены transition guard. Вложенный run допустим только из application callback после завершения transition.
4. **Замена классов.** Публичные resolver, facility, codec, binding verifier и runtime SPI разрешают explicit host implementation/decorator. Final facade и Internal classes не являются inheritance API. Default bindings задаются один раз при сборке; выбранная замена сохраняет declared version/profile и проходит тот же corpus. Runtime decorator делегирует одному frame owner; полная runtime replacement заменяет default целиком и обязана реализовать LIFO/token/poison/lifetime invariants. Второй executor, registry current или скрытый Laravel rebind после boot запрещён. Для изменения одного facility не требуется замена executor.
5. **Blocking hooks и observations.** Relation/admission/required state changes — typed SPI participants общего journal, их failure отменяет activation. Runtime events — immutable versioned observations: успешный enter после required activation; успешный restore после проверки предыдущего frame; failure/poison — с явным outcome. Они содержат observed identity/ref и frame correlation, без credentials/handles/token authority; listener не выводит target из ambient current. Событие enter не означает успех callback или business receipt. По умолчанию observer отсутствует; маленький PHP-only sink не требует Illuminate/PSR dispatcher. Laravel adapter может подключить свой dispatcher.
6. **Ошибки observer.** Каждый listener вызывается изолированно; Throwable фиксируется защищённым diagnostic sink, остальные listeners всё равно получают observation. Ошибка observer/diagnostic не меняет успешный transition, не маскирует callback/cleanup failure и не препятствует cleanup. Observer не имеет veto и не выполняет tenant effects; необходимые эффекты оформляются SPI или owning durable command/outbox. Внутри listener повторные enter/restore/registration отвергаются guard. Событие poison не разрешает tenant IO. Durable resource lifecycle events принадлежат physical owner и публикуются после receipt, а не этим in-memory sink.

Первый conformance использует небольшой synthetic plugin вне kernel: новый capability ID, resolver decorator, явный facility replacement и два product hooks. Он доказывает отсутствие обязательных product imports, конфликты/порядок, nested frame state и ошибки observers. Полный plugin marketplace, UI panels, hot reload и универсальный rule engine не входят в v1.


## Freeze P1.1: semantic/wire/SPI 1.0.0

Нормативные machine-readable outputs этого item:

| Manifest | Содержание |
|---|---|
| [tenant.context.v1.schema.json](../../contracts/tenant.context.v1.schema.json) | Draft 2020-12, `tenant.context/1`, `$defs/instanceMarker` для единого `tenant.binding/1` |
| [api.v1.json](../../contracts/api.v1.json) | PHP type shapes/signatures, один runtime, public SPI/decorators, composition/transition protocol, verifier и neutral Vaulter seam |
| [profiles.v1.json](../../contracts/profiles.v1.json) | Точные v1 modes/lanes/facilities/slots, stream policy, EXT-01…06 и deferred qualification |
| [errors.v1.json](../../contracts/errors.v1.json) | Stable reason codes, exception types, phase/state effect; observer diagnostics отдельно от veto |
| [ownership.v1.json](../../contracts/ownership.v1.json) | Каждая portable identity, physical operation и поле existing CoreX migration; один writer на P5.1 и P5.4 |

Сигнатуры в manifests — исходная specification; текущие канонические PHP namespaces
и сигнатуры отражены в снимках API пакетов. `Tenant` — instance facade; запись `Tenant::…` в таблицах обозначает
responsibility. Kernel namespace `Tenant\`, зависимости PHP и pure-PHP Packman; optional
commands/receipts/FSM находятся в `Tenant\Postgres\`, neutral producer SPI — у
Vaulter. Имя capability открыто; квалифицированный набор ограничен profiles.
Формат refs/границы и конкретные имена берутся из этих versioned manifests, остальной
текст описывает те же инварианты. Изменение frozen semantic/wire/SPI требует явной
version/contract поправки и актуализации corpus его owning item.

Wire всегда содержит `schema`, `mode`, `profileId`, `profileVersion`, `extensions`.
Для tenant/single обязательны namespace/isolationId/resourceRef/incarnation/routeRevision;
для central эти поля запрещены, включая null, и extensions пуст. Central профиль
устанавливает deny-default tenant bindings и сохраняет explicit named control IO;
он не пытается проверить несуществующий tenant marker. Missing не сериализуется.
JSON Schema проверяет shape; codec дополнительно отвергает duplicate keys, NaN,
oversize/depth, ненормализованные/слишком длинные UTF-8 identities; registry/producer
trust и fresh admission не выводятся из валидности JSON. Unknown required extension
отказывает до resource mutation; известные product refs проверяются после activation.
Ожидаемый routeRevision из durable ref при mismatch даёт `stale_route`; логический
новый enter может получить свежий trusted route, durable job молча не retarget.

Composition root фиксирует bindings, profiles и declarations до первого resolve.
Одна explicit compatible replacement заменяет точно названный target; chains и
несколько replacers запрещены. Edges к заменённому ID переводятся на replacement.
Topological order стабилен с ASCII ID tie-break; зависимости не переставляют product
раньше resource. Выбранный verifier — host service binding с собственной capability,
не второй Facility/executor; compiler учитывает capabilities host services, а для
participant dependencies строит graph. У каждой facility отдельные instance/state
на frame; runtime записывает journal **до** prepare. Prepare только сохраняет state,
activate меняет ресурсы, restore компенсирует и verify подтверждает baseline.

Фабрика может быть process-lived, mutable state — только frame-local. Restore
previous binding делает свежий lookup/incarnation check и пытается выполнить весь
cleanup даже при ошибке lookup. Старые generation handles не оживают после A→B→A.
Неправильный token не снимает top frame. Direct restore с открытым tracked stream
отказывает до mutation; приложение может закрыть stream и повторить. `run`, которое
завершает callback с живым stream/token, не выдаёт success: это fatal `frame_leak`,
poison/quarantine и остановка boundary. Нативный stream не возвращается наружу;
guarded profile не предоставляет raw path. Прямой IO по утёкшему PHP resource/path
не объявляется защищённым generation wrapper.

Entered/restored observations публикуются после успешных state+generation changes.
Failure/poison имеют отдельные outcome; entered не обещает callback/receipt success.
Observer/diagnostic Throwable не меняет transition или cleanup; каждый observer
вызывается отдельно, без veto, tenant effects и lifecycle reentry. Dispatcher
не обязателен. Полная runtime replacement снимает default binding и проходит тот
же corpus; decorator делегирует одному owner, facade/provider используют его же.

Binding marker `tenant.binding/1` связывает ownerId/resourceRef/incarnation и
recoveryGeneration. Catalog дополнительно хранит routeRevision/availability/facets.
Public verifier сравнивает catalog с **actual** marker того connection/resource,
к которому привязан proof. Reconnect требует нового proof; template signature,
connectionIdentity, имя БД и restoreEpoch из backup его не заменяют. P5.1 реализует
protocol у CoreX; P5.4 переиспользует helper/tests и меняет persistence/wiring на
единственный tenant-postgres owner. Без внешнего recovery anchor full catalog
restore/replay unsupported/fail-closed; minimal runtime не ждёт P4. SQL schema names,
DDL и migration files появятся в owning implementation items; здесь фиксируются
logical schema identities и существующий field split, без второго migration set.

Neutral Vaulter `JobPublisher::prepare(request, origin)` фиксирует immutable
`PreparedJob` до регистрации afterCommit; `publish(prepared)` проверяет тот же
original target. Drive владеет public task ID→internal job mapping; bridge не
импортирует private Drive classes. Delete purge и scan готовятся до deferred callback;
purge continuation передаёт original PublicationContext, не ambient B. Scan immediate
publication тоже требует verified target. Bridge restoration идёт перед Vaulter
validator; unique locks разделены по original resource/incarnation. Эти source changes
и actual worker/IO proof назначены P5.2, здесь не выполнялись.

Общий Tenant ID допускает namespace до 48 ASCII chars и opaque ID до 96 UTF-8 bytes.
Vaulter projection имеет отдельную явную область: namespace ≤32 bytes, id ≤96 bytes,
их суммарная длина ≤44 UTF-8 bytes; UUID36 допускает namespace ≤8 bytes.
Формат `t1.` + unpadded base64url(один byte длины namespace || namespace || id)
инъективен и занимает максимум 63 ASCII bytes, укладываясь в tenant varchar(64)
через `VaulterSchema::tenant()` → `HostKeyColumns::add()`. Idempotency varchar(191)
не задаёт размер Drive tenant column. Boot требует `ids.host_keys=string` и проверку
actual columns ≥64; bigint/uuid/ulid host keys не поддерживают этот scalar profile.
Оба resolver SPI используют эту функцию и fresh owner mapping. Выход за пределы
отказывает до записи, без усечения/хеширования; incarnation проверяется отдельно.
Core/Drive остаются tenancy-neutral, optional bridge принадлежит Vaulter family.

P1.1 подтверждает contract completeness и синтаксис, не metaschema/corpus validator,
не runtime/worker/DDL/consumer qualification. Fixtures и validator принадлежат
P1.2/P1.3. Licence/support/security и conditional product policies не выдаются за
закрытые release/business inputs. Source/evidence hashes и ограничения передаются
в PLAN1 artifacts/P1.1 и knowledge snapshot; `passed` относится только к выполненным
V1–V6 этого contract item.
