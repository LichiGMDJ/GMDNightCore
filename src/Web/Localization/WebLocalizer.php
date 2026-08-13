<?php

declare(strict_types=1);

namespace NightCore\Web\Localization;

use NightCore\Core\Config;

/**
 * Runtime localization for human-facing Night Core web panels.
 *
 * Geometry Dash protocol endpoints are intentionally excluded. The localizer
 * operates only on a strict script whitelist, so wire responses and JSON APIs
 * remain byte-for-byte compatible with the game/client integrations.
 */
final class WebLocalizer
{
    private const SUPPORTED = ['ru', 'en'];
    private const COOKIE = 'gdps_web_language';
    private const HUMAN_PAGES = [
        'dashboard.php',
        'staffAdmin.php',
        'eventAdmin.php',
        'songAdmin.php',
        'tournaments.php',
    ];

    private static bool $booted = false;

    private function __construct(
        private string $locale,
        private string $serverName
    ) {
    }

    public static function bootForCurrentScript(): void
    {
        if (self::$booted || PHP_SAPI === 'cli') {
            return;
        }

        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['SCRIPT_FILENAME'] ?? ''));
        if (!in_array($script, self::HUMAN_PAGES, true)) {
            return;
        }

        $locale = self::detectLocale();
        $serverName = trim(Config::get('NIGHTCORE_SERVER_NAME', 'GDPS') ?? 'GDPS');
        if ($serverName === '') {
            $serverName = 'GDPS';
        }

        $localizer = new self($locale, $serverName);
        self::$booted = true;
        $localizer->persistPreference();
        ob_start([$localizer, 'filterHtml']);
    }

    public function filterHtml(string $html): string
    {
        if ($html === '' || stripos($html, '<html') === false) {
            return $html;
        }

        $html = preg_replace(
            '/<html\b([^>]*)\blang=("|\')[^"\']*(\2)([^>]*)>/iu',
            '<html$1lang="' . $this->locale . '"$4>',
            $html,
            1
        ) ?? $html;

        $html = preg_replace_callback('/>([^<>]+)</u', function (array $match): string {
            $raw = $match[1];
            $trimmed = trim($raw);
            if ($trimmed === '') {
                return $match[0];
            }

            $translated = $this->translateText($trimmed);
            if ($translated === $trimmed) {
                return $match[0];
            }

            $leadingLength = strlen($raw) - strlen(ltrim($raw));
            $trailingLength = strlen($raw) - strlen(rtrim($raw));
            $leading = $leadingLength > 0 ? substr($raw, 0, $leadingLength) : '';
            $trailing = $trailingLength > 0 ? substr($raw, -$trailingLength) : '';
            return '>' . $leading . $translated . $trailing . '<';
        }, $html) ?? $html;

        $html = preg_replace_callback(
            '/\b(placeholder|title|aria-label|data-confirm)=("|\')([^"\']*)(\2)/iu',
            function (array $match): string {
                return $match[1] . '=' . $match[2] . $this->translateAttribute($match[3]) . $match[4];
            },
            $html
        ) ?? $html;

        return $this->injectLanguageUi($html);
    }

    private static function detectLocale(): string
    {
        $requested = strtolower(trim((string) ($_GET['lang'] ?? '')));
        if (in_array($requested, self::SUPPORTED, true)) {
            return $requested;
        }

        $cookie = strtolower(trim((string) ($_COOKIE[self::COOKIE] ?? '')));
        if (in_array($cookie, self::SUPPORTED, true)) {
            return $cookie;
        }

        $configured = strtolower(trim(Config::get('WEB_DEFAULT_LANGUAGE', 'ru') ?? 'ru'));
        return in_array($configured, self::SUPPORTED, true) ? $configured : 'ru';
    }

    private function persistPreference(): void
    {
        $requested = strtolower(trim((string) ($_GET['lang'] ?? '')));
        if (!in_array($requested, self::SUPPORTED, true) || headers_sent()) {
            return;
        }

        $basePath = trim(Config::get('BASE_PATH', '/') ?? '/');
        $cookiePath = $basePath === '' ? '/' : '/' . trim($basePath, '/');
        if ($cookiePath === '') {
            $cookiePath = '/';
        }

        setcookie(self::COOKIE, $this->locale, [
            'expires' => time() + 31536000,
            'path' => $cookiePath,
            'secure' => $this->isHttps(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    private function translateText(string $text): string
    {
        // Hide the framework/product name from player-facing pages while keeping
        // internal namespaces, APIs and repository naming unchanged.
        $text = str_replace(['Night Core V1', 'Night Core', 'NIGHT CORE'], $this->serverName, $text);

        if ($this->locale !== 'ru') {
            return $text;
        }

        $map = self::russianMap();
        if (isset($map[$text])) {
            return $map[$text];
        }

        $patterns = [
            '/^Account #(\d+) · Tournament staff$/u' => 'Аккаунт #$1 · Администратор турниров',
            '/^Account #(\d+)$/u' => 'Аккаунт #$1',
            '/^Role #(\d+) · priority (-?\d+)$/u' => 'Роль #$1 · приоритет $2',
            '/^Match #(\d+)$/u' => 'Матч #$1',
            '/^Level #(\d+)$/u' => 'Уровень #$1',
            '/^Locks: (.+)$/u' => 'Закрытие: $1',
            '/^Place (\d+)$/u' => 'Место $1',
            '/^(\d+) days$/u' => '$1 дн.',
            '/^Maximum file size: (.+)\.$/u' => 'Максимальный размер файла: $1.',
            '/^Signed in as (.+)\.$/u' => 'Вход выполнен: $1.',
            '/^Signed in as (.+)$/u' => 'Вход выполнен: $1',
            '/^Tournament created\. ID: (\d+)\.$/u' => 'Турнир создан. ID: $1.',
            '/^Creator added to tournament\. Participant ID: (\d+)\.$/u' => 'Создатель добавлен в турнир. ID участника: $1.',
            '/^Bracket generated: (\d+) matches across (\d+) rounds\.$/u' => 'Сетка создана: $1 матч(а/ей), $2 раунд(а/ов).',
            '/^Match opened for (\d+) minute\(s\)\.$/u' => 'Матч открыт на $1 мин.',
            '/^Custom song (\d+) deleted\.$/u' => 'Локальная песня $1 удалена.',
            '/^Song uploaded\. ID: (\d+) — (.+)$/u' => 'Песня загружена. ID: $1 — $2',
            '/^SFX uploaded\. ID: (\d+) — (.+)$/u' => 'SFX загружен. ID: $1 — $2',
            '/^Assigned (.+) to the selected role\.$/u' => '$1 назначен(а) на выбранную роль.',
            '/^Role saved\. ID: (\d+)\.$/u' => 'Роль сохранена. ID: $1.',
            '/^Deletion scheduled for (.+) UTC\.$/u' => 'Удаление аккаунта запланировано на $1 UTC.',
            '/^(Cancelled|Ended) event #(\d+)\.$/u' => '$1 событие #$2.',
            '/^No active (Daily|Weekly|Event) level\.$/u' => 'Сейчас нет активного уровня: $1.',
            '/^Round of (\d+)$/u' => '1/$1 финала',
        ];

        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, $text) === 1) {
                $result = preg_replace($pattern, $replacement, $text);
                return is_string($result) ? $result : $text;
            }
        }

        return $text;
    }

    private function translateAttribute(string $value): string
    {
        $value = str_replace(['Night Core V1', 'Night Core', 'NIGHT CORE'], $this->serverName, $value);
        if ($this->locale !== 'ru') {
            return $value;
        }

        $map = [
            'Username' => 'Имя пользователя',
            'Password' => 'Пароль',
            'Current password' => 'Текущий пароль',
            'Username or account ID' => 'Имя пользователя или ID аккаунта',
            'P1 level ID' => 'ID уровня участника 1',
            'P2 level ID' => 'ID уровня участника 2',
            'Tournament description' => 'Описание турнира',
            'How creators compete and how winners are selected' => 'Как проходит турнир и как определяется победитель',
            'Dashboard sections' => 'Разделы панели',
            'Close' => 'Закрыть',
            'Tournament tabs' => 'Разделы турнира',
            'Change this event status?' => 'Изменить статус этого события?',
            'Delete this role and unassign its members?' => 'Удалить эту роль и снять её со всех участников?',
        ];

        return $map[$value] ?? $value;
    }

    /** @return array<string,string> */
    private static function russianMap(): array
    {
        return [
            // Common UI.
            'Sign in' => 'Войти',
            'Sign out' => 'Выйти',
            'Log out' => 'Выйти',
            'Register' => 'Регистрация',
            'Sign in / Register' => 'Войти / Регистрация',
            'Username' => 'Имя пользователя',
            'Password' => 'Пароль',
            'Email' => 'Эл. почта',
            'Repeat password' => 'Повторите пароль',
            'Current password' => 'Текущий пароль',
            'Name' => 'Название',
            'Description' => 'Описание',
            'Rules' => 'Правила',
            'Status' => 'Статус',
            'Action' => 'Действие',
            'Actions' => 'Действия',
            'Save' => 'Сохранить',
            'Delete' => 'Удалить',
            'Remove' => 'Снять',
            'Protected' => 'Защищено',
            'Close' => 'Закрыть',
            'None' => 'Нет',
            'Enabled' => 'Включено',
            'Disabled' => 'Выключено',
            'enabled' => 'включено',
            'disabled' => 'выключено',
            'Continue?' => 'Продолжить?',
            'ID' => 'ID',
            'Size' => 'Размер',
            'Download' => 'Скачать',
            'Account' => 'Аккаунт',
            'Player' => 'Игрок',
            'Points' => 'Очки',
            'Correct' => 'Верно',
            'Winner' => 'Победитель',
            'Rewards' => 'Награды',
            'Claims' => 'Получено',
            'Window' => 'Период',

            // Framework branding / page titles after server-name substitution.
            'dashboard' => 'панель',
            'custom songs' => 'локальные песни',
            'events' => 'события',
            'staff' => 'персонал',

            // Dashboard.
            'Public GDPS content and account features.' => 'Контент GDPS и управление аккаунтом.',
            'Songs / SFX' => 'Песни / SFX',
            'Daily / Weekly / Event' => 'Daily / Weekly / Event',
            'Current GDPS rotations' => 'Текущие ротации GDPS',
            'Active Daily, Weekly and Event slots are read directly from this GDPS.' => 'Активные Daily, Weekly и Event берутся напрямую с этого GDPS.',
            'Daily' => 'Daily',
            'Weekly' => 'Weekly',
            'Event' => 'Event',
            'Upload limits' => 'Лимиты загрузки',
            'Songs' => 'Песни',
            'Library' => 'Библиотека',
            'Files are validated by the server before they are stored.' => 'Перед сохранением сервер проверяет файлы.',
            'Browse public media without signing in.' => 'Публичную медиатеку можно просматривать без входа.',
            'Media uploads are currently disabled by the server owner.' => 'Владелец сервера отключил загрузку медиа.',
            'Uploads are locked' => 'Загрузка недоступна',
            'Use the account button to sign in or create a GDPS account.' => 'Войдите или создайте аккаунт GDPS через кнопку аккаунта.',
            'Authenticated uploader' => 'Авторизованный загрузчик',
            'Signed in as' => 'Вход выполнен:',
            'Uploads are checked automatically for file type, integrity and available storage.' => 'Тип файла, целостность и свободное место проверяются автоматически.',
            'Upload song' => 'Загрузить песню',
            'Song title' => 'Название песни',
            'Author / artist' => 'Автор / исполнитель',
            'MP3 file' => 'MP3-файл',
            'Upload SFX' => 'Загрузить SFX',
            'SFX name' => 'Название SFX',
            'OGG file' => 'OGG-файл',
            'Local songs' => 'Локальные песни',
            'Local SFX' => 'Локальные SFX',
            'Song' => 'Песня',
            'No local songs yet.' => 'Локальных песен пока нет.',
            'No local SFX yet.' => 'Локальных SFX пока нет.',
            'GDPS account' => 'Аккаунт GDPS',
            'Your profile' => 'Ваш профиль',
            'Account access' => 'Вход в аккаунт',
            'Security confirmations' => 'Подтверждение опасных действий',
            'Security confirmation settings are temporarily unavailable. Password confirmation remains enabled.' => 'Настройки подтверждения временно недоступны. Подтверждение паролем остаётся включённым.',
            'Controls repeated password prompts for account deletion, staff role management and Event actions. Changing this setting always requires your password.' => 'Управляет повторным запросом пароля при удалении аккаунта, изменении ролей персонала и действиях Event. Для изменения этой настройки пароль требуется всегда.',
            'Require current password for every sensitive action' => 'Требовать текущий пароль для каждого опасного действия',
            'Recommended on every shared or remotely accessible device.' => 'Рекомендуется на общих устройствах и при удалённом доступе.',
            'Current password to save this setting' => 'Текущий пароль для сохранения настройки',
            'Save security setting' => 'Сохранить настройку безопасности',
            'Account deletion' => 'Удаление аккаунта',
            'Account deletion is disabled by the server owner.' => 'Удаление аккаунтов отключено владельцем сервера.',
            'Account deletion settings are temporarily unavailable.' => 'Настройки удаления аккаунта временно недоступны.',
            'Deletion scheduled' => 'Удаление запланировано',
            'Your account remains active until that date.' => 'До этой даты аккаунт остаётся активным.',
            'Cancel deletion' => 'Отменить удаление',
            'Choose how long the account remains active. Credentials and email are anonymized after the deadline; published levels remain.' => 'Выберите, сколько ещё будет активен аккаунт. После срока данные входа и почта обезличиваются, а опубликованные уровни сохраняются.',
            'Per-action password confirmation is disabled. Exact username confirmation is still mandatory.' => 'Подтверждение паролем для каждого действия отключено. Точное подтверждение имени пользователя всё равно обязательно.',
            'Delete after' => 'Удалить через',
            'Type your username to confirm' => 'Введите имя пользователя для подтверждения',
            'Schedule account deletion' => 'Запланировать удаление аккаунта',
            'Use the same username and password as in Geometry Dash.' => 'Используйте те же имя пользователя и пароль, что и в Geometry Dash.',
            'Creates a normal account in this GDPS.' => 'Создаёт обычный аккаунт на этом GDPS.',
            'Create account' => 'Создать аккаунт',

            // Creator Major.
            'Creator Major' => 'Creator Major',
            'Creator tournaments, brackets and Pick’em predictions.' => 'Турниры создателей, турнирная сетка и прогнозы Pick’em.',
            'Tournament staff' => 'Администратор турниров',
            'Tournaments' => 'Турниры',
            'No tournaments yet.' => 'Турниров пока нет.',
            'Tournaments are disabled' => 'Турниры отключены',
            'The base Geometry Dash 2.2 server is running normally. Set TOURNAMENTS_MODE=enabled to expose Creator Major features again. Existing tournament data stays in the database.' => 'Базовый сервер Geometry Dash 2.2 работает нормально. Установите TOURNAMENTS_MODE=enabled, чтобы снова включить Creator Major. Существующие данные турниров сохраняются.',
            'Creators' => 'Создатели',
            'Bracket' => 'Сетка',
            'Match pts' => 'Очки за матч',
            'Champion pts' => 'Очки за чемпиона',
            'Overview' => 'Обзор',
            'Pick’em' => 'Pick’em',
            'Leaderboard' => 'Таблица лидеров',
            'Admin' => 'Управление',
            'Major status' => 'Статус Major',
            'Pick’em opens' => 'Начало Pick’em',
            'Champion pick locks' => 'Закрытие прогноза на чемпиона',
            'Tournament start' => 'Начало турнира',
            'No rules published yet.' => 'Правила пока не опубликованы.',
            'Single-elimination bracket' => 'Сетка на выбывание',
            'Winners advance automatically to the next slot.' => 'Победители автоматически проходят в следующий раунд.',
            'Bracket has not been generated yet.' => 'Сетка ещё не создана.',
            'TBD' => 'Ожидается',
            'Pick' => 'Выбрать',
            'Selected' => 'Выбрано',
            'Champion Pick' => 'Прогноз на чемпиона',
            'Predictions are disabled.' => 'Прогнозы отключены.',
            'Sign in with your GDPS account to make predictions.' => 'Войдите в аккаунт GDPS, чтобы делать прогнозы.',
            'Current pick:' => 'Текущий выбор:',
            'none' => 'нет',
            'My predictions' => 'Мои прогнозы',
            'No picks yet.' => 'Прогнозов пока нет.',
            'Champion' => 'Чемпион',
            'Pick’em leaderboard' => 'Таблица лидеров Pick’em',
            'No resolved predictions yet.' => 'Пока нет рассчитанных прогнозов.',
            'Prediction rewards' => 'Награды за прогнозы',
            'Sign in to see your rewards.' => 'Войдите, чтобы увидеть свои награды.',
            'No unclaimed rewards.' => 'Нет неполученных наград.',
            'Champion prediction' => 'Прогноз на чемпиона',
            'Match prediction' => 'Прогноз на матч',
            'Claim' => 'Получить',
            'Tournament controls' => 'Управление турниром',
            'Open Pick’em' => 'Открыть Pick’em',
            'Mark Live' => 'Запустить турнир',
            'Creator account' => 'Аккаунт создателя',
            'Seed' => 'Посев',
            'Add creator' => 'Добавить создателя',
            'Bracket size' => 'Размер сетки',
            'Generate bracket' => 'Создать сетку',
            'Match operations' => 'Управление матчами',
            'Save levels' => 'Сохранить уровни',
            'Open match' => 'Открыть матч',
            'Judging' => 'Судейство',
            'Resolve winner' => 'Определить победителя',
            'Audit trail' => 'Журнал действий',
            'Create new Creator Major' => 'Создать новый Creator Major',
            'Slug' => 'Короткий адрес',
            'Tournament starts' => 'Начало турнира',
            'Optional end' => 'Дата окончания (необязательно)',
            'Match pick points' => 'Очки за прогноз матча',
            'Champion pick points' => 'Очки за прогноз чемпиона',
            'Match reward JSON' => 'Награда за матч (JSON)',
            'Champion reward JSON' => 'Награда за чемпиона (JSON)',
            'Create tournament' => 'Создать турнир',
            'Draft' => 'Черновик',
            'Pick’em Open' => 'Pick’em открыт',
            'Live' => 'Идёт',
            'Completed' => 'Завершён',
            'Cancelled' => 'Отменён',
            'Scheduled' => 'Запланирован',
            'Active' => 'Активен',
            'Eliminated' => 'Выбыл',
            'Withdrawn' => 'Снялся',
            'Pending' => 'Ожидает',
            'Wrong' => 'Неверно',
            'Void' => 'Аннулирован',
            'Quarterfinal' => 'Четвертьфинал',
            'Semifinal' => 'Полуфинал',
            'Final' => 'Финал',
            'Tournament opened for predictions.' => 'Турнир открыт для прогнозов.',
            'Tournament marked active.' => 'Турнир переведён в активный статус.',
            'Match level entries updated.' => 'Уровни матча обновлены.',
            'Match moved to judging.' => 'Матч переведён на судейство.',
            'Final resolved. Tournament champion crowned.' => 'Финал завершён. Чемпион турнира определён.',
            'Match resolved. Winner advanced automatically.' => 'Матч завершён. Победитель автоматически прошёл дальше.',
            'Champion prediction saved.' => 'Прогноз на чемпиона сохранён.',
            'Match prediction saved.' => 'Прогноз на матч сохранён.',
            'Prediction reward claimed.' => 'Награда за прогноз получена.',
            'Reward is unavailable or was already claimed.' => 'Награда недоступна или уже получена.',
            'Sign in to use Pick’em.' => 'Войдите, чтобы использовать Pick’em.',
            'Invalid username or password.' => 'Неверное имя пользователя или пароль.',
            'Username and password are required.' => 'Введите имя пользователя и пароль.',
            'Too many login attempts. Try again later.' => 'Слишком много попыток входа. Попробуйте позже.',
            'Account could not be loaded after login.' => 'Не удалось загрузить аккаунт после входа.',
            'Account name or ID is required.' => 'Укажите имя или ID аккаунта.',
            'Account was not found.' => 'Аккаунт не найден.',
            'Invalid request token. Refresh the page and try again.' => 'Срок действия формы истёк. Обновите страницу и попробуйте снова.',

            // Staff panel.
            'Roles, permissions and staff presentation for this GDPS.' => 'Роли, права и оформление персонала этого GDPS.',
            'Staff management login' => 'Вход в управление персоналом',
            'Repeated failed sign-in attempts are temporarily blocked.' => 'После нескольких неудачных попыток вход временно блокируется.',
            'Per-action password confirmation:' => 'Подтверждение паролем для каждого действия:',
            'Change this setting in /dashboard.php. Staff login always requires a password.' => 'Изменить настройку можно в /dashboard.php. Для входа в панель персонала пароль требуется всегда.',
            'Create role' => 'Создать роль',
            'Priority' => 'Приоритет',
            'Geometry Dash badge' => 'Значок Geometry Dash',
            'Moderator' => 'Модератор',
            'Elder / administrator' => 'Старший модератор / администратор',
            'Badge text' => 'Текст значка',
            'Badge color' => 'Цвет значка',
            'Comment color' => 'Цвет комментария',
            'Username color' => 'Цвет имени',
            'Permissions' => 'Права',
            'Confirm current password' => 'Подтвердите текущий пароль',
            'Assign staff' => 'Назначить персонал',
            'Username or account ID' => 'Имя пользователя или ID аккаунта',
            'Role' => 'Роль',
            'Select role' => 'Выберите роль',
            'Assign role' => 'Назначить роль',
            'Owner accounts cannot be changed here. Only owners can grant staff.manage.' => 'Аккаунты владельцев здесь изменить нельзя. Право staff.manage могут выдавать только владельцы.',
            'Save role' => 'Сохранить роль',
            'Delete role' => 'Удалить роль',
            'Current staff' => 'Текущий персонал',
            'Badge' => 'Значок',
            'No staff assignments yet.' => 'Назначений персонала пока нет.',
            'Staff assignment removed.' => 'Роль сотрудника снята.',
            'Role deleted. Staff assigned to it were unassigned.' => 'Роль удалена. Она снята со всех сотрудников.',
            'Staff management access required.' => 'Требуется доступ к управлению персоналом.',
            'This account does not have staff management permission.' => 'У этого аккаунта нет права на управление персоналом.',
            'Staff session expired or permission was removed. Sign in again.' => 'Сессия персонала истекла или право было отозвано. Войдите снова.',
            'Sign-in temporarily unavailable. Try again later.' => 'Вход временно недоступен. Попробуйте позже.',
            'Role name is required.' => 'Введите название роли.',
            'Role was not found.' => 'Роль не найдена.',
            'Invalid role ID.' => 'Некорректный ID роли.',
            'Invalid account.' => 'Некорректный аккаунт.',
            'Staff assignment was not found.' => 'Назначение персонала не найдено.',
            'Only an owner can grant staff.manage.' => 'Только владелец может выдать staff.manage.',
            'Only an owner can assign staff.manage.' => 'Только владелец может назначать staff.manage.',
            'Owner accounts cannot be changed here.' => 'Аккаунты владельцев здесь изменить нельзя.',
            'You cannot edit a role at or above your own priority.' => 'Нельзя изменять роль с приоритетом не ниже вашего.',
            'You cannot delete a role at or above your own priority.' => 'Нельзя удалить роль с приоритетом не ниже вашего.',
            'You cannot assign a role at or above your own priority.' => 'Нельзя назначить роль с приоритетом не ниже вашего.',
            'You cannot change staff at or above your own priority.' => 'Нельзя изменять сотрудника с приоритетом не ниже вашего.',
            'You cannot remove staff at or above your own priority.' => 'Нельзя снимать роль с сотрудника с приоритетом не ниже вашего.',
            'Role priority must be lower than your own.' => 'Приоритет роли должен быть ниже вашего.',
            'Colors must use #RRGGBB format.' => 'Цвет должен быть в формате #RRGGBB.',
            'Unknown staff action.' => 'Неизвестное действие панели персонала.',

            // Permission descriptions stored in the database.
            'Create roles, edit permissions and assign staff' => 'Создание ролей, изменение прав и назначение персонала',
            'Suggest star ratings' => 'Предлагать звёздочный рейтинг',
            'Rate levels' => 'Оценивать уровни',
            'Feature levels' => 'Выдавать Featured',
            'Set epic/mythic/legendary rating' => 'Выдавать Epic / Legendary / Mythic',
            'Set demon difficulty' => 'Устанавливать сложность Demon',
            'Delete levels' => 'Удалять уровни',
            'Moderate and delete comments' => 'Модерировать и удалять комментарии',
            'Ban or unban users' => 'Банить и разбанивать пользователей',
            'Mute or unmute users' => 'Мутить и размучивать пользователей',
            'Manage user moderation state' => 'Управлять состоянием модерации пользователей',
            'View moderation reports' => 'Просматривать жалобы',
            'Manage local songs and SFX' => 'Управлять локальными песнями и SFX',
            'Create and manage creator tournaments and brackets' => 'Создавать и управлять турнирами создателей и сетками',
            'Resolve tournament matches and tournament winners' => 'Завершать матчи и определять победителей турниров',
            'Rate or unrate levels. Commands: !rate <1-10>, !unrate' => 'Оценивать и снимать оценку с уровней. Команды: !rate <1-10>, !unrate',
            'Feature levels. Command: !feature <1-10>' => 'Выдавать Featured. Команда: !feature <1-10>',
            'Set Epic, Legendary or Mythic. Commands: !epic <1-10>, !legendary <1-10>, !mythic <1-10>' => 'Выдавать Epic, Legendary или Mythic. Команды: !epic <1-10>, !legendary <1-10>, !mythic <1-10>',
            'Set demon difficulty. Command: !demon easy|medium|hard|insane|extreme' => 'Устанавливать сложность Demon. Команда: !demon easy|medium|hard|insane|extreme',
            'Ban or unban users. Commands: !ban <username>, !unban <username>' => 'Банить и разбанивать пользователей. Команды: !ban <username>, !unban <username>',
            'Exclude or restore users in leaderboards. Commands: !leaderboardban <username>, !leaderboardunban <username>' => 'Исключать и возвращать пользователей в таблицы лидеров. Команды: !leaderboardban <username>, !leaderboardunban <username>',
            'Queue a Daily level. Command: !daily' => 'Добавлять уровень в очередь Daily. Команда: !daily',
            'Queue a Weekly level. Command: !weekly' => 'Добавлять уровень в очередь Weekly. Команда: !weekly',
            'Force a Daily active immediately. Commands: !daily now, !daily force' => 'Немедленно активировать Daily. Команды: !daily now, !daily force',
            'Force a Weekly active immediately. Commands: !weekly now, !weekly force' => 'Немедленно активировать Weekly. Команды: !weekly now, !weekly force',
            'Create an event. Command: !event duration=<1h-90d> reward=<type:amount,...> [start=now]' => 'Создавать событие. Команда: !event duration=<1h-90d> reward=<type:amount,...> [start=now]',
            'Change an existing event. Command: !eventchange [duration=<1h-90d>] [reward=<type:amount,...>] [start=now]' => 'Изменять существующее событие. Команда: !eventchange [duration=<1h-90d>] [reward=<type:amount,...>] [start=now]',
            'Create or fully replace an event. Command: !eventset duration=<1h-90d> reward=<type:amount,...> [start=now]' => 'Создавать или полностью заменять событие. Команда: !eventset duration=<1h-90d> reward=<type:amount,...> [start=now]',

            // Event panel.
            'Event slots, rewards, claims and audit.' => 'Слоты Event, награды, получение наград и журнал действий.',
            'Event management login' => 'Вход в управление событиями',
            'Repeated failed sign-in attempts are temporarily blocked when the staff security tables are available.' => 'При нескольких неудачных попытках вход временно блокируется.',
            'Event management permission required.' => 'Требуется право на управление событиями.',
            'Event session expired or permission was removed. Sign in again.' => 'Сессия событий истекла или право было отозвано. Войдите снова.',
            'Invalid event.' => 'Некорректное событие.',
            'Event not found.' => 'Событие не найдено.',
            'Unknown action.' => 'Неизвестное действие.',
            'events.set permission required.' => 'Требуется право events.set.',
            'Per-action password confirmation:' => 'Подтверждение паролем для каждого действия:',
            'Change this setting in /dashboard.php. Event-panel login always requires a password. Geometry Dash commands are unaffected.' => 'Изменить настройку можно в /dashboard.php. Для входа в панель Event пароль требуется всегда. Команды Geometry Dash не меняются.',
            'Events' => 'События',
            'Level' => 'Уровень',
            'End' => 'Завершить',
            'Cancel' => 'Отменить',
            'Claim history' => 'История получения наград',
            'Audit' => 'Журнал',

            // Legacy local-song panel.
            'Upload an MP3 hosted directly by this installation. The generated Song ID can be entered as a custom song ID in Geometry Dash.' => 'Загрузите MP3 прямо на этот сервер. Полученный Song ID можно использовать как ID пользовательской песни в Geometry Dash.',
            'Admin token' => 'Токен администратора',
            'Local song library' => 'Библиотека локальных песен',
            'Custom song not found.' => 'Локальная песня не найдена.',
            'Choose an MP3 file.' => 'Выберите MP3-файл.',
            'Unknown file upload error.' => 'Неизвестная ошибка загрузки файла.',
            'The uploaded MP3 was not received as a valid HTTP upload.' => 'MP3 не был получен как корректная HTTP-загрузка.',
            'The MP3 exceeds the PHP upload_max_filesize limit.' => 'MP3 превышает лимит PHP upload_max_filesize.',
            'The MP3 exceeds the form upload limit.' => 'MP3 превышает лимит формы.',
            'The MP3 upload was interrupted.' => 'Загрузка MP3 была прервана.',
            'The server has no upload temporary directory.' => 'На сервере отсутствует временный каталог загрузки.',
            'The server could not write the uploaded MP3.' => 'Сервер не смог записать загруженный MP3.',
            'A PHP extension stopped the upload.' => 'Расширение PHP остановило загрузку.',

            // Shared runtime messages.
            'Signed in.' => 'Вход выполнен.',
            'Signed out.' => 'Выход выполнен.',
            'Unknown dashboard action.' => 'Неизвестное действие панели.',
            'Upload temporarily unavailable. Try again later.' => 'Загрузка временно недоступна. Попробуйте позже.',
            'Rotation data is not available yet.' => 'Данные ротаций пока недоступны.',
            'Passwords do not match.' => 'Пароли не совпадают.',
            'Enter a valid email address.' => 'Введите корректный адрес электронной почты.',
            'This username is already registered.' => 'Это имя пользователя уже занято.',
            'Username must contain no more than 20 characters.' => 'Имя пользователя должно быть не длиннее 20 символов.',
            'Registration was rejected. Check the fields or try again later.' => 'Регистрация отклонена. Проверьте поля или попробуйте позже.',
            'Account created. It must be activated before you can sign in.' => 'Аккаунт создан. Перед входом его нужно активировать.',
            'Media uploads are disabled.' => 'Загрузка медиа отключена.',
            'Sign in with a GDPS account before uploading media.' => 'Перед загрузкой медиа войдите в аккаунт GDPS.',
            'Choose an OGG file.' => 'Выберите OGG-файл.',
            'The uploaded OGG was not received as a valid HTTP upload.' => 'OGG не был получен как корректная HTTP-загрузка.',
            'The uploaded OGG is empty.' => 'Загруженный OGG пуст.',
            'The uploaded MP3 is empty.' => 'Загруженный MP3 пуст.',
        ];
    }

    private function injectLanguageUi(string $html): string
    {
        $css = '<link rel="stylesheet" href="assets/web-localization.css">';
        if (stripos($html, 'assets/web-localization.css') === false) {
            $html = preg_replace('/<\/head>/i', $css . "\n</head>", $html, 1) ?? $html;
        }

        $label = $this->locale === 'ru' ? 'Язык' : 'Language';
        $ru = $this->languageUrl('ru');
        $en = $this->languageUrl('en');
        $switcher = '<nav class="gdps-language-switcher" aria-label="' . $this->escape($label) . '">'
            . '<span>' . $this->escape($label) . '</span>'
            . '<a href="' . $this->escape($ru) . '" class="' . ($this->locale === 'ru' ? 'active' : '') . '">RU</a>'
            . '<a href="' . $this->escape($en) . '" class="' . ($this->locale === 'en' ? 'active' : '') . '">EN</a>'
            . '</nav>';

        if (stripos($html, 'gdps-language-switcher') === false) {
            $html = preg_replace('/<\/body>/i', $switcher . "\n</body>", $html, 1) ?? ($html . $switcher);
        }

        return $html;
    }

    private function languageUrl(string $locale): string
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        }

        $params = $_GET;
        $params['lang'] = $locale;
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        return $path . ($query !== '' ? '?' . $query : '');
    }

    private function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (!Config::getBool('TRUST_PROXY_HEADERS', false)) {
            return false;
        }
        $forwarded = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
        return $forwarded === 'https';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
