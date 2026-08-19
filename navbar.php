<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<nav class="navbar">
    <a href="index.php" class="nav-link <?= $current_page == 'index.php' ? 'active' : '' ?>">Список туров</a>
    
    <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
        <a href="participants.php" class="nav-link <?= $current_page == 'participants.php' ? 'active' : '' ?>">База туристов</a>
        <a href="clients.php" class="nav-link <?= $current_page == 'clients.php' ? 'active' : '' ?>">Клиенты (LTV)</a>
        <a href="schedule.php" class="nav-link <?= $current_page == 'schedule.php' ? 'active' : '' ?>">📅 Расписание</a>
        <a href="analytics.php" class="nav-link <?= $current_page == 'analytics.php' ? 'active' : '' ?>">📊 Аналитика</a>
        <a href="tours.php" class="nav-link <?= in_array($current_page, ['tours.php', 'tour_builder.php']) ? 'active' : '' ?>">🗺 Каталог туров</a>
        <a href="settings.php" class="nav-link <?= $current_page == 'settings.php' ? 'active' : '' ?>">⚙️ Настройки</a>
    <?php endif; ?>
    
    <a href="logout.php" class="nav-link" style="margin-left: auto; color: #EF4444; border: 1px solid #FECACA;">Выйти</a>
</nav>