# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [0.15.14] - 2026-06-25
### Changed
- Веб-обновление больше не зависит от **локально установленного** `update.sh`: при нажатии «Обновить» `apply_update` сначала качает свежий `update.sh` с GitHub (SOCKS5 + зеркала jsDelivr/gh-proxy/ghproxy.net/ghfast.top), проверяет его и запускает именно его. Это спасает роутеры со старым апдейтером (старая методика обновления не могла довести себя до актуальной). Если скачать свежий скрипт не удалось — откат на локальный `update.sh`, то есть прежнее поведение без регрессии
### Fixed
- Окно обновления в веб-интерфейсе мгновенно показывало 100% и старый лог («Already at latest … Nothing to do» от прошлого запуска), хотя обновление не запускалось/не отображалось. Причина: `status_update` отдавал хвост `update.log` от **предыдущего** прогона, а JS (`logDone`) ловил в нём «Nothing to do» и сразу ставил статус `done`. Теперь `apply_update` обнуляет `update.log` свежей меткой перед стартом, так что окно всегда показывает прогресс именно текущего обновления
### Fixed
- **Реальная причина петель `loopback connection detected`**: инбаунды `socks-in` (1081) и `http-in` (1082) слушали `0.0.0.0`, то есть были **открытым прокси для всей сети**. На роутере с публичным IP в них стучались сканеры/абуз и просили прокси соединиться в т.ч. с самим собой → шквал петель, пока ядро не падало (после чего watchdog снимал redirect и VPN «отключался»). Этот путь шёл мимо iptables-цепочки `XRAY`, поэтому фикс firewall из 0.15.9 его не закрывал. Теперь оба инбаунда биндятся на `127.0.0.1` — ими пользуется только сам роутер (статус-пробы, update.sh, changelog через `127.0.0.1:1081`), снаружи они недоступны. Изменено в обоих генераторах конфига: `xray-manager.sh` и `api.php`
### Fixed
- Обновление через веб-интерфейс молча не отрабатывало под TSPU: `update.sh` тянул `VERSION` и `install.sh` только через `curl_gh` (SOCKS5 + прямой запрос), без GitHub-зеркал, которые были только в `install.sh`. Если xray не запущен (или VPN-сервер недоступен и watchdog на паузе), прямой запрос к `raw.githubusercontent.com` сбрасывался TSPU — проверка/скачивание падали, а UI показывал старое состояние. Теперь в `update.sh` есть `gh_mirrors()` и `download_gh()`, и `curl_gh()`/загрузка `install.sh` идут через тот же набор зеркал (jsDelivr `cdn`/`testingcf`, прокси `gh-proxy.com`/`ghproxy.net`/`ghfast.top`), что и в `install.sh`
- Примечание: чтобы получить этот фикс, апдейтер должен сам обновиться — но именно он и не мог пробиться через TSPU. На устройстве со старым `update.sh` обновление нужно один раз запустить вручную через install.sh (с зеркала), после чего веб-обновление заработает
### Changed
- `install.sh`: расширен список GitHub-зеркал в `gh_mirrors()`. Для файлов репозитория добавлен альтернативный edge jsDelivr (`testingcf.jsdelivr.net`), для всех запросов — резервные прокси `ghproxy.net` и `ghfast.top`. Нерабочие зеркала (`mirror.ghproxy.com`, `raw.gitmirror.com`) исключены, чтобы не тратить время на таймауты. Это повышает шанс скачать VERSION/manifest/файлы при блокировке `raw.githubusercontent.com`/`github.com` со стороны TSPU

## [0.15.9] - 2026-06-24
### Fixed
- Xray периодически «сам отключался»: в логах шёл шквал `app/proxyman/inbound: loopback connection detected`, после чего ядро падало и watchdog снимал redirect (трафик уходил мимо VPN). Причина — цепочка `XRAY` в `nat` редиректила на dokodemo (порт 1080) соединения, чьим назначением был сам роутер на портах инбаундов Xray (1080/1081/1082) или собственные адреса роутера (включая публичный WAN-IP). `followRedirect` читал локальный порт как «оригинальное назначение», и Xray набирал сам себя → петля. Особенно ярко проявлялось когда socks/http-инбаунды (1081/1082) доступны с WAN и по ним стучатся боты. Исправлено в `setup_firewall()`: перед правилами REDIRECT добавлены `RETURN` для портов 1080/1081/1082 и для всех собственных адресов роутера (`ip -o addr`), для IPv4 и IPv6
- `install.sh`: `curl_gh()` теперь после прямого соединения пробует GitHub-зеркала (jsDelivr для файлов репозитория, gh-proxy для release-ассетов) — TSPU нередко сбрасывает `raw.githubusercontent.com`/`github.com`, и зеркала позволяют установке/обновлению не падать. Плюс `download_manifest()` повторяет запрос VERSION до 3 раз с паузой, а сообщение об ошибке подсказывает обойти через `--version vX.Y.Z`

## [0.15.8] - 2026-06-17
### Fixed
- `curl_gh()` теперь пробует SOCKS5 с коротким таймаутом (8–15 с), и если не прошло — делает прямой запрос. Это решает ситуацию когда xray запущен но VPN-сервер недоступен (watchdog на паузе): раньше зависало на полный таймаут SOCKS5, теперь быстро переходит на прямое соединение

## [0.15.7] - 2026-06-17
### Fixed
- Проверка обновлений была медленной и нестабильной: TSPU иногда пропускает прямые соединения роутера с GitHub, иногда нет. Исправлено изменением логики `curl_gh()`: если xray запущен — сразу используется SOCKS5 (127.0.0.1:1081), без попытки прямого соединения и ожидания таймаута. Это устраняет и задержку (не ждём 15–30 с таймаута), и нестабильность (TSPU не видит запрос). Если xray не запущен — прямое соединение как раньше (друг без TSPU не заметит разницы)

## [0.15.6] - 2026-06-17
### Fixed
- `update.sh`: добавлен `curl_gh()` — SOCKS5 fallback через `127.0.0.1:1081` при блокировке GitHub TSPU. Теперь `latest_version()` и все загрузки в update.sh тоже обходят TSPU, кнопка "Проверить" в UI работает
- `api.php`: `check_update` больше не отдаёт пустую/невалидную строку при чтении из кеша — JS получал исключение "The string did not match the expected pattern" при попытке разобрать не-JSON
- `api.php`: `changelog_full` переведён на `/opt/bin/curl` + SOCKS5 fallback при блокировке

## [0.15.5] - 2026-06-17
### Fixed
- `install.sh` не мог скачать VERSION и manifest.json на роутерах где TSPU блокирует прямые соединения с GitHub. Добавлена функция `curl_gh()`: сначала пробует прямое соединение, при ошибках 6/7/28/35 автоматически повторяет через локальный xray SOCKS5 прокси (`--socks5-hostname 127.0.0.1:1081`), который обходит TSPU. Все inline curl-вызовы к GitHub переведены на `curl_gh`/`curl_fetch`

## [0.15.4] - 2026-06-17
### Fixed
- Обновление падало с `curl: (28) Connection timed out` внутри `install.sh` на шаге "Checking internet connectivity". В режиме `--upgrade` этот чек теперь пропускается — скрипт только что был скачан, значит сеть работает. Для свежей установки чек сохранён, но с retry 3×30 s вместо одного 10 s

## [0.15.3] - 2026-06-17
### Fixed
- Обновление падало с `curl: (6) Could not resolve host: release-assets.githubusercontent.com` / `github.com` когда эти домены не были в DNS-кеше AdGuard Home (ТСПУ, временный сбой апстрима). Исправлено изменением порядка источников: теперь первым идёт `raw.githubusercontent.com/v{N}/install.sh` — тот же домен, что используется для проверки VERSION и потому всегда закеширован. GitHub release assets остались как fallback
- Все curl в `update.sh` переведены на `/opt/bin/curl` (явный путь) и получили `--retry 3 --retry-delay 5` чтобы пережить кратковременные сбои сети

## [0.15.2] - 2026-06-17
### Fixed
- `xray-manager.sh`: функция `log()` теперь проверяет `logs_enabled` перед любым вызовом `logger` — при отключённых логах ни один `logger -t xray-mgr` не выполняется, в том числе при старте, стопе, генерации конфига и настройке firewall. Явные проверки `_logs_enabled` в watchdog-функциях убраны как дублирование
- `update.sh --cron`: ежедневный крон-джоб больше не пишет «auto_update disabled, skipping» в `/opt/var/log/xray/update.log` когда логи отключены. Ранее это давало одну запись на диск каждую ночь в 04:30 даже с выключенным auto-update

## [0.15.1] - 2026-06-17
### Fixed
- Watchdog теперь уважает настройку «Логи» (`logs_enabled`): когда логи отключены — ни одна строка из watchdog не пишется в syslog. Переодические проверки каждые 30 с не логировались и раньше, теперь также молчат события смены состояния (пауза / восстановление) и старт watchdog-процесса. Читается `features.json` только при смене состояния, не в цикле опроса

## [0.15.0] - 2026-06-17
### Fixed
- Роутер перестаёт отвечать (в том числе на 192.168.1.1) когда VPN-сервер недоступен (ТСПУ или даунтайм): iptables перенаправлял трафик в xray, xray висел в ожидании TCP-соединения, таблица conntrack переполнялась и ядро дропало все новые TCP. Исправлено watchdog-процессом, который снимает перенаправление если сервер недоступен 90 с подряд и восстанавливает его автоматически

### Added
- **Watchdog** (`xray-manager.sh`): фоновый процесс проверяет TCP-доступность VPN-сервера каждые 30 с. После 3 неудач подряд — снимает PREROUTING-хук (трафик идёт напрямую). При восстановлении сервера — хук возвращается автоматически. Состояние хранится в `/opt/var/run/xray-watchdog.state`
- Новая константа `nf_conntrack_tcp_timeout_syn_sent` = 10 с вместо 120 с: зависшие попытки соединения очищаются в 12 раз быстрее даже пока watchdog ещё не сработал
- Статус «Пауза (сервер недоступен)» в веб-интерфейсе — оранжевый цвет, live-dot гаснет, баннер с пояснением

### Changed
- Проверка IP (`check_ips`): оба curl-запроса (VPN IP и реальный IP) теперь выполняются **параллельно** — время ответа сократилось с ~10–12 с до ~1–2 с
- Реальный IP кешируется на 5 минут — повторные проверки мгновенны
- Если watchdog поставил redirect на паузу — VPN IP не проверяется, экономя лишний запрос
- Тайм-аут VPN IP check снижен с 7 с до 5 с

## [0.14.0] - 2026-06-15
### Changed
- Полный редизайн веб-интерфейса: новая палитра Azure (электрик-синий на угольном фоне), все дизайн-токены переработаны, добавлены переменные `--bg-elev`, `--border-strong`, `--accent-soft`, `--accent-glow`, `--ring`, `--shadow-sm`, `--text3`, `--radius-sm`
- Ребрендинг: сервис переименован в **VKeen** в видимых пользователю местах (заголовок браузера, шапка, экран входа). Технические идентификаторы не затронуты
- Шапка стала компактной: убран большой градиентный hero-блок, добавлен аккуратный логотип-чип с иконкой щита
- Все компоненты обновлены: мягкие тени `--shadow-sm`, скругления `--radius-sm`, плавный подъём при ховере (`translateY(-1px)`), фокус-ринг `var(--ring)` на всех интерактивных элементах
- Тосты получили цветную левую грань вместо полной цветной рамки
- Пустые состояния оформлены единообразно через класс `.empty-state`
- Все хардкод-цвета в JS-шаблонах (`rgba(99,102,241,...)`, `rgba(6,182,212,...)` и т.д.) заменены на CSS-классы `badge-soft`, `badge-soft-cyan`, `dot.on/off/warn`, `v2g-head`
- Обновление модального окна, тоггл-переключатели, скроллбары, оверлеи — все приведены к новой палитре
- Светлая тема Azure пересчитана в соответствии с новой палитрой

## [0.13.5] - 2026-06-14
### Fixed
- Экран обновления больше не считает новое обновление «завершённым» мгновенно из-за слова «OK» в хвосте лога от предыдущего запуска. Теперь API обрезает лог до последнего старта `update.sh`, а JS детектирует завершение только по строке «Установка завершена» (не по голому «OK»)

## [0.13.4] - 2026-06-14
### Changed
- Обновление стало значительно быстрее: файлы, которые не изменились с текущей версией, теперь не скачиваются — хеш SHA256 установленного файла сравнивается с манифестом, и при совпадении файл берётся локально. Defaults (шаблонные файлы) тоже не скачиваются если уже присутствуют на роутере. В лог выводится счётчик «скачано / не изменилось»

## [0.13.3] - 2026-06-14
### Changed
- Экран обновления полностью переработан: вместо сырых DEBUG-логов теперь пошаговый прогресс с иконками (Скачивание → Резервная копия → Применение файлов → Миграции → Завершение), анимированный прогресс-бар с процентами и плавными переходами
- Логи скрыты по умолчанию, раскрываются кнопкой «Показать лог» (для диагностики ошибок)
- Отображается версия перехода (например v0.13.2 → v0.13.3) прямо в экране прогресса

## [0.13.2] - 2026-06-14
### Fixed
- После установки обновления бейдж «обновление доступно» больше не остаётся на иконке после перезагрузки страницы: кеш проверки версии (`localStorage`) очищается при успешном завершении обновления

## [0.13.1] - 2026-06-14
### Fixed
- Смена сервера у домена/IP теперь применяется **мгновенно**. Раньше каждое изменение сбрасывало весь список перенаправления (ipset) и перезапускало AdGuard с прогревом ~12с — из-за чего казалось, что «работает только последний выбранный сервер». Теперь смена подключения (Прокси/сервер) только перезапускает Xray (~1с), не трогая ipset и AdGuard; полный путь нужен лишь при переключении в/из «Напрямую»
- Прогрев списка перенаправления ждёт готовности AdGuard, а не фиксированные 12 секунд — маршрут поднимается быстрее
- Кнопка «Обновить» больше не показывается, когда обновлений нет (атрибут `hidden` перебивался стилем `.btn`); заодно корректно скрываются вкладка WireGuard при выключенной функции и другие скрытые элементы

## [0.13.0] - 2026-06-14
### Fixed
- Обновление через веб больше не зависает в статусе «applying» / «already_running». Причина: `update.sh` запускался в дереве процессов веб-сервера, и перезапуск lighttpd в ходе обновления убивал апдейтер до завершения. Теперь `update.sh` полностью отсоединяется (`setsid`) — перезапуск lighttpd ему не страшен
- Блокировка повторного запуска теперь по факту живости процесса (PID), а не по таймеру в 5 минут — «зависший» статус больше не мешает повторить
### Changed
- Обновление стало значительно быстрее: для обновлений кода (PHP/shell) сервисы больше не перезапускаются — AdGuard и Xray не трогаются, VPN не отваливается во время апдейта (файлы читаются заново сами)
- Переработан интерфейс обновлений: понятное состояние «у вас последняя версия», карточка доступного обновления, раздел «История изменений» со всеми версиями, устойчивый прогресс (можно дождаться уже идущего обновления вместо ошибки)
### Added
- Кнопка «Убрать дубли» в разделе «Правила»: одноразовая чистка — удаляет домены, уже покрытые включёнными v2fly-списками, и повторяющиеся IP
- Приоритет дедупликации: v2fly-списки авторитетны. Ручной домен-дубль убирается, кроме случая с заданным отдельным подключением (исключение) — такие помечаются бейджем «исключение v2fly»; при добавлении дубля без подключения он пропускается (со счётчиком пропущенных)

## [0.12.0] - 2026-06-14
### Added
- Маршрутизация по правилам: для каждого домена, IP или списка v2fly можно выбрать подключение — через активный сервер (Прокси), через конкретный сервер или напрямую (в обход VPN)
- Единый раздел «Правила» вместо отдельных вкладок «Домены» и «IP»: фильтры (Все / Домены / IP / Списки v2fly), поиск, общий счётчик
- Массовые операции: выбор правил галочками, смена подключения сразу у нескольких, удаление выбранных
- Выбор типа совпадения домена: «Поддомены» (домен и поддомены) или «Точный» (только указанный домен)
- При пакетном добавлении можно сразу назначить подключение всему набору
### Changed
- «Напрямую» — полный обход VPN: домен исключается из ipset AdGuard, статический IP — из ipset через производный `direct_ips.txt`, трафик вообще не заходит в Xray
- Xray-конфиг теперь группирует домены/IP по целевому серверу (по одному правилу на сервер) — нагрузка на роутер не растёт, разделение происходит внутри Xray
- Поиск v2fly остался отдельной вкладкой; выбор подключения для списков — в разделе «Правила»
### Migration
- Полностью обратносовместимо: существующие домены, IP и списки продолжают работать через активный сервер. Исключения хранятся в `rules/rule_targets.json`; при его отсутствии поведение идентично прежнему

## [0.11.24] - 2026-06-13
### Fixed
- v2fly каталог обрезался на ~1000 записях из-за лимита GitHub contents API — теперь используется Git Trees API который отдаёт все записи без ограничений
- Если каталог содержит меньше 800 записей — считается повреждённым и пересоздаётся автоматически
- Telegram, WhatsApp, Twitter, YouTube и всё на буквы t-z теперь находится в поиске

## [0.11.23] - 2026-06-13
### Added
- Поиск v2fly на русском: «ватсап» → whatsapp, «телеграм» → telegram, «гугл» → google и т.д.

## [0.11.22] - 2026-06-13
### Fixed
- v2fly: используется `ss-downloader` если есть (обрабатывает `include:` рекурсивно), иначе curl с GitHub — поиск и загрузка работают одинаково на любом роутере
- install.sh: автоматически устанавливает `ss-downloader` под нужную архитектуру (mipsle/arm64/arm/amd64)

## [0.11.21] - 2026-06-13
### Fixed
- v2fly: загрузка из правильного источника (`master/data/{name}`) с парсингом формата `domain:example.com`
- v2fly: каталог теперь получается через GitHub API — отображаются только реально существующие списки
- «openai» и другие имена которых нет в v2fly теперь показывают понятную ошибку вместо падения

## [0.11.20] - 2026-06-13
### Fixed
- «Ошибка: already_running» при повторном нажатии «Обновить» после зависшего обновления — стейт-файл старше 5 минут теперь считается устаревшим и не блокирует новый запуск

## [0.11.19] - 2026-06-13
### Fixed
- v2fly списки больше не требуют `ss-downloader` — загружаются напрямую с GitHub (`v2fly/domain-list-community`) через curl

## [0.11.18] - 2026-06-12
### Fixed
- UI обновления больше не зависает: завершение определяется по тексту лога («Already at latest», «Nothing to do», «OK») даже если старый update.sh не записал state корректно

## [0.11.17] - 2026-06-12
### Fixed
- Кнопка «Обновить» скрыта когда установлена последняя версия

## [0.11.16] - 2026-06-12
### Fixed
- CI: объединены auto-tag и release в один workflow — PAT не нужен, релиз создаётся автоматически при изменении VERSION

## [0.11.15] - 2026-06-12
### Fixed
- Переключение обратно на предыдущий сервер больше не останавливает VPN
- Анимация спиннера вынесена в отдельный элемент — больше не накладывается на зелёную точку активного сервера

## [0.11.14] - 2026-06-12
### Changed
- Кнопка «Применить» убрана — сервер переключается сразу при нажатии с анимацией спиннера

## [0.11.13] - 2026-06-12
### Changed
- Кнопка переименована: «Применить» → «Обновить»
- Заполнен changelog для версий 0.11.4–0.11.12 — описание изменений теперь отображается в окне обновления

## [0.11.12] - 2026-06-12
### Fixed
- `install.sh`: retry `curl_fetch` up to 3 times with 3s delay on transient DNS/network errors instead of failing immediately.

## [0.11.11] - 2026-06-12
### Fixed
- `update_adguard_ipset()`: regex now handles `ipset: []` inline YAML format written by AdGuard Home on fresh setup. Previously the regex only matched multiline format so the ipset section was never updated — domain routing silently didn't work even with AdGuard configured.

## [0.11.10] - 2026-06-12
### Fixed
- `install.sh` `mf_get`: added `-d doc_root='' -d open_basedir=''` to php-cgi call. `php.ini` sets `doc_root=/opt/share/www` which blocked execution of helper scripts in `/tmp`, causing php-cgi to fall back to `api.php` and return `{"error":"Unknown action"}` as the manifest version.

## [0.11.9] - 2026-06-12
### Fixed
- `install.sh` `mf_get`: keep `REDIRECT_STATUS` (required by force-cgi-redirect) but override `SCRIPT_FILENAME` to the helper script and clear `QUERY_STRING`/`REQUEST_METHOD` to prevent api.php from being executed.

## [0.11.8] - 2026-06-12
### Fixed
- `install.sh` `mf_get`: removed `exec` from subshell — BusyBox ash does not pass `VAR=val` assignments through exec builtins, so `_MF`/`_EXPR` were not reaching php-cgi.

## [0.11.7] - 2026-06-12
### Fixed
- `install.sh` `mf_get`: unset CGI env vars (`REQUEST_METHOD`, `SCRIPT_FILENAME`, `REDIRECT_STATUS`, `QUERY_STRING`) in a subshell before calling php-cgi to prevent lighttpd-inherited vars from redirecting execution to api.php.

## [0.11.6] - 2026-06-12
### Fixed
- `install.sh` `mf_get`: use `env -i` to prevent inheriting CGI env vars from lighttpd. When `SCRIPT_FILENAME=api.php` and `QUERY_STRING=action=apply_update` were inherited, php-cgi executed api.php instead of the helper, triggering a second `update.sh` process.

## [0.11.5] - 2026-06-11
### Fixed
- `install.sh`: stale lock detection now also checks file age (>30 min = stale). Prevents false "Another install running" errors when the lock PID was recycled by an unrelated process after a crash.

## [0.11.4] - 2026-06-11
### Added
- VLESS support in `xray-manager.sh` shell config generator: `build_vless_outbound()` with Reality/TLS support and URL-decode for base64 public keys. Config generator now reads from `keys.json` and `cached_servers.json` (current data model) instead of legacy `list.json`.
### Fixed
- `api.php`: always merge hardcoded default subnets (including `10.50.0.0/24`) into `trusted_subnets` so WireGuard VPN admin access works even on old saved configs.
- `api.php`: prevent concurrent update runs — if state file shows `starting`/`downloading`/`applying`, return `already_running` instead of spawning a second `update.sh`.
- `ci`: shellcheck `--severity=warning` on `install.sh` to suppress SC2016 false positives for PHP strings in single quotes.

## [0.11.3] - 2026-06-11
### Fixed
- `install.sh` on older Entware (2024): `php8-cgi` was outputting HTTP response headers (`X-Powered-By`, `Content-Type`) even with `-q`, causing `mf_get` to return garbage instead of the manifest field. Added `_strip_cgi_headers()` awk filter and switched from `$argv` to `getenv()` for passing values to the PHP helper (more reliable across CGI versions).
- `geoip:private` in Xray routing config caused `failed to open file: geoip.dat` — MIPS Entware xray-core does not include geo data files. Replaced with explicit private IP ranges.
- `status` action always showed "Остановлен" after page refresh: `shell_exec` returns `"0\n"` (with newline), but comparison was `=== '0'`. Added `trim()` and `pgrep -x xray` fallback.

## [0.11.2] - 2026-06-11
### Fixed
- `install.sh` crashed with `sh: php: not found`. Root cause: `mf_get` used `php -r` to parse the manifest, but PHP wasn't installed yet (chicken-and-egg). Added `bootstrap_php()` before `download_manifest()` to ensure PHP is ready first. Also fixed for Entware setups where only `php8-cgi`/`php-cgi` is present (no CLI `php` binary — CGI binaries don't support `-r`): `_find_php()` auto-detects the available binary and mode; in CGI mode `mf_get` writes a one-line helper script to `/tmp` and calls `php8-cgi -f`. Verified on Keenetic Hopper where `php` doesn't exist but `php8-cgi` is installed.

## [0.11.1] - 2026-06-11
### Fixed
- `install.sh` crashed immediately on fresh install with `sh: REL_BASE: parameter not set`. The variable was only assigned inside the `--repo` CLI flag handler but not at the global level — so every install without `--repo` died before downloading the manifest.
- Keenetic model detection no longer prints a scary `WARN` if the model string isn't found; changed to `INFO` and widened the awk pattern to match more firmware versions.

## [0.11.0] - 2026-06-11
### Added
- AdGuard Home fully automated in `install.sh`: deploys `AdGuardHome.yaml` (DNS on port 53, Cloudflare+Google DOH upstreams, web UI on :3000), `adguardhome.conf` (Entware startup options), and the critical `10-dns-redirect.sh` Keenetic netfilter hook that redirects all LAN DNS queries through AGH. Applies iptables rules immediately, no reboot needed.
- `docs/adguard-setup.md`: full explanation of the AGH ↔ Xray DNS→ipset→iptables chain, step-by-step setup guide, troubleshooting.
- Wizard step 4 (server picker): search input for lists with >5 servers.

### Fixed
- Status bar skeleton animation no longer persists after data loads (stRealIp and stMem were not removing `.loading` class).
- Header bell/gear icons now visible — white text/border on gradient background.
- Emojis in wizard and Settings replaced with matching inline SVG icons.
- VPN IP and Real IP no longer block the main status response (moved to separate `check_ips` action called asynchronously). Status loads in <1s instead of up to 10s.
- Wizard step 4: server items showed `name [empty-badge] :` — fixed by parsing `vless://host:port` link client-side; now shows `Name · VLESS · host:port · source`. Port strips trailing slashes.
- Wizard step 4: `select_server` was called with wrong parameter (`tag` instead of `id`) — server was never actually selected.
- Full VPN: `xray-manager.sh start()` was calling broken shell `generate_config()` on every boot, causing xray not to start after reboot. Fixed to use existing `config.json` if valid (preserves UI-generated config). Auto-connect on reboot now works.
- Full VPN: source IP lookup now filters FAILED/INCOMPLETE ARP entries and adds DHCP leases + ndmc API as fallbacks.
- Full VPN devices panel: shows colored dot (green = IP resolved, orange = IP missing) and a warning banner with one-click Xray restart when device has no IP.
- "Restart wizard" confirm message clarifies that keys, subscriptions and domains are NOT deleted.
- `S99wireguard` now has start/stop/restart/status and respects `features.json.wireguard` on boot.

## [0.10.0] - 2026-06-10
### Added
- Onboarding wizard (5 steps): UI password, Keenetic admin password (with live RCI test), first subscription URL, server picker, WireGuard toggle.
- Login overlay for remote access (LAN bypass via trusted subnets).
- Session-based auth + rate-limit on failed logins.
- Settings modal (gear icon): version & auto-update toggle, WireGuard/AdGuard/theme switches, password management, rollback & restart-wizard buttons.
- Auto-update: `update.sh` with `--check / --apply / --cron / --rollback`, API endpoints, bell icon with badge, modal with changelog and progress polling.
- Light theme + auto (follows system).
- Stacking toast system with success/error/warn/info, swipe-to-dismiss on mobile.
- Skeleton shimmer placeholders for status bar.
- Mobile polish: scroll-snap tabs, 16px inputs (no iOS zoom), full-screen modals on small screens.
- Live status dot in header (pulse animation).
- `prefers-reduced-motion` respect.

### Changed
- `S99wireguard` now supports start/stop/restart/status and respects `features.json.wireguard` on boot.

## [0.9.0] - 2026-06-10
### Added
- Initial public release skeleton.
- POSIX `install.sh` for one-command installation on Keenetic with Entware.
- Web UI on port 91 (lighttpd + php-cgi): server selection, subscriptions, manual keys, domains, IPs, v2fly catalog, devices (Full VPN by MAC), WireGuard peers, logs.
- `xray-manager.sh` for config generation, firewall rules, ipset.
- Integration with Keenetic RCI API for device list.
- Optional WireGuard (toggle in UI).

### Fixed
- Memory display in status bar — `free -m` on BusyBox actually returns KB; divide by 1024.
