<?php
require_once 'config.php';

$all_channels = [];
$res = $conn->query("SELECT * FROM channels ORDER BY category ASC, name ASC");
while ($row = $res->fetch_assoc()) {
    $all_channels[] = $row;
}

$categories = array_unique(array_column($all_channels, 'category'));
sort($categories);

$active_stream = '';
$active_name   = '';

if (isset($_GET['play'])) {
    $decoded = base64_decode($_GET['play'], true);
    if ($decoded !== false && filter_var($decoded, FILTER_VALIDATE_URL)) {
        $active_stream = htmlspecialchars($decoded, ENT_QUOTES, 'UTF-8');
    }
}
if (empty($active_stream) && !empty($all_channels)) {
    $active_stream = htmlspecialchars($all_channels[0]['url'],  ENT_QUOTES, 'UTF-8');
    $active_name   = htmlspecialchars($all_channels[0]['name'], ENT_QUOTES, 'UTF-8');
}

if (isset($_GET['api']) && $_GET['api'] === 'channels') {
    header('Content-Type: application/json');
    echo json_encode(array_map(fn($c) => [
        'id'       => (int)$c['id'],
        'name'     => $c['name'],
        'logo'     => $c['logo'],
        'category' => $c['category'],
        'play'     => base64_encode($c['url']),
    ], $all_channels));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Spotzfy Live &mdash; IPTV Streaming</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' };</script>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest/dist/hls.min.js"></script>
    <style>
        .channel-card { transition: transform .15s, border-color .15s; }
        .channel-card:hover { transform: scale(1.05); }
        .channel-card.active { border-color: #ef4444; background: rgba(239,68,68,.08); }
        .cat-btn.active-cat { background: #ef4444; color: #fff; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #0f172a; }
        ::-webkit-scrollbar-thumb { background: #374151; border-radius: 3px; }
    </style>
</head>
<body class="bg-gray-100 dark:bg-slate-950 text-gray-900 dark:text-white min-h-screen transition-colors duration-200">

<header class="bg-white dark:bg-slate-900 shadow sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between gap-3 flex-wrap">

        <h1 class="text-xl font-extrabold tracking-widest text-red-600 dark:text-red-500 shrink-0 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor">
                <path d="M21 3H3a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5v2H7v2h10v-2h-1v-2h5a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zm0 14H3V5h18v12z"/>
                <path d="M10 8.5l6 3.5-6 3.5z"/>
            </svg>
            SPOTZFY <span class="text-gray-700 dark:text-gray-300 font-light text-sm ml-1">LIVE</span>
        </h1>

        <div class="flex-1 min-w-[180px] max-w-sm relative">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input id="search-input" type="search" placeholder="Search channels..."
                class="w-full pl-9 pr-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 border border-gray-300 dark:border-gray-700 text-sm focus:outline-none focus:ring-2 focus:ring-red-500">
        </div>

        <div class="flex items-center gap-3">
            <button onclick="toggleTheme()" title="Toggle Theme"
                    class="p-2 bg-gray-200 dark:bg-gray-800 rounded-full hover:bg-gray-300 dark:hover:bg-gray-700 transition">
                <svg id="icon-sun" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="5"/>
                    <line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                    <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                </svg>
                <svg id="icon-moon" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                </svg>
            </button>
            <a href="admin.php" class="flex items-center gap-1.5 text-xs text-gray-400 hover:text-white transition">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
                Admin
            </a>
        </div>
    </div>
</header>

<main class="max-w-7xl mx-auto px-4 py-5">

    <?php if ($active_stream): ?>
    <div class="bg-black rounded-xl overflow-hidden shadow-2xl mb-4 aspect-video max-h-[520px]">
        <video id="live-player" class="w-full h-full" controls autoplay playsinline></video>
    </div>
    <div id="now-playing" class="flex items-center justify-center gap-2 text-sm text-gray-400 mb-5">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-red-500 shrink-0" viewBox="0 0 24 24" fill="currentColor">
            <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/>
            <circle cx="12" cy="12" r="3" fill="#ef4444"/>
        </svg>
        Now Playing: <span class="text-white font-semibold"><?= $active_name ?: 'Channel' ?></span>
    </div>
    <?php else: ?>
    <div class="flex flex-col items-center justify-center py-20 bg-white dark:bg-slate-900 rounded-xl mb-6 gap-4">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-16 h-16 text-gray-300 dark:text-gray-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
            <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
        </svg>
        <p class="text-gray-500 text-center">No channels loaded.<br>
            Please sync an M3U playlist from the
            <a href="admin.php" class="text-red-500 hover:underline">Admin Panel</a>.
        </p>
    </div>
    <?php endif; ?>

    <div class="flex flex-wrap gap-2 items-center mb-4">
        <span class="flex items-center gap-1.5 text-xs bg-gray-200 dark:bg-gray-800 px-3 py-1 rounded-full text-gray-500 shrink-0">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
            </svg>
            <?= count($all_channels) ?> Channels
        </span>
        <button class="cat-btn active-cat text-xs px-3 py-1 rounded-full bg-gray-200 dark:bg-gray-700 transition" data-cat="all">All</button>
        <?php foreach ($categories as $cat): ?>
        <button class="cat-btn text-xs px-3 py-1 rounded-full bg-gray-200 dark:bg-gray-700 hover:bg-red-500 hover:text-white transition"
                data-cat="<?= htmlspecialchars($cat, ENT_QUOTES) ?>">
            <?= htmlspecialchars($cat, ENT_QUOTES) ?>
        </button>
        <?php endforeach; ?>
    </div>

    <div id="channel-grid" class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8 gap-3">
        <?php foreach ($all_channels as $ch):
            $enc  = base64_encode($ch['url']);
            $name = htmlspecialchars($ch['name'],     ENT_QUOTES, 'UTF-8');
            $logo = htmlspecialchars($ch['logo'],     ENT_QUOTES, 'UTF-8');
            $cat  = htmlspecialchars($ch['category'], ENT_QUOTES, 'UTF-8');
        ?>
        <a href="?play=<?= $enc ?>"
           class="channel-card bg-white dark:bg-slate-900 p-2 rounded-lg text-center shadow border-2 border-transparent flex flex-col items-center justify-center gap-1"
           data-name="<?= strtolower($name) ?>" data-cat="<?= strtolower($cat) ?>"
           onclick="playChannel(event, '<?= $enc ?>', '<?= addslashes($name) ?>')">
            <img src="<?= $logo ?>" alt="<?= $name ?>"
                 class="h-10 w-10 object-contain rounded bg-gray-100 dark:bg-gray-800 p-0.5"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
            <span class="h-10 w-10 hidden items-center justify-center rounded bg-gray-100 dark:bg-gray-800">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
                </svg>
            </span>
            <span class="text-[11px] font-semibold line-clamp-2 leading-tight"><?= $name ?></span>
            <span class="text-[9px] text-gray-400"><?= $cat ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <p id="no-result" class="hidden text-center text-gray-500 py-10">No channels found matching your search.</p>
</main>

<footer class="text-center text-xs text-gray-500 dark:text-gray-700 py-6">
    &copy; <?= date('Y') ?> Spotzfy Live. All rights reserved.
</footer>

<script>
function toggleTheme() {
    const isDark = document.documentElement.classList.toggle('dark');
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
    document.getElementById('icon-sun').classList.toggle('hidden', !isDark);
    document.getElementById('icon-moon').classList.toggle('hidden', isDark);
}
(function () {
    const saved = localStorage.getItem('theme');
    if (saved === 'light') {
        document.documentElement.classList.remove('dark');
        document.getElementById('icon-sun').classList.remove('hidden');
        document.getElementById('icon-moon').classList.add('hidden');
    }
})();

const videoEl = document.getElementById('live-player');
let hlsInstance = null;

function loadStream(url) {
    if (!videoEl) return;
    if (hlsInstance) { hlsInstance.destroy(); hlsInstance = null; }
    if (Hls.isSupported()) {
        hlsInstance = new Hls({ lowLatencyMode: true });
        hlsInstance.loadSource(url);
        hlsInstance.attachMedia(videoEl);
        hlsInstance.on(Hls.Events.MANIFEST_PARSED, () => videoEl.play().catch(() => {}));
    } else if (videoEl.canPlayType('application/vnd.apple.mpegurl')) {
        videoEl.src = url;
        videoEl.play().catch(() => {});
    }
}

<?php if ($active_stream): ?>
loadStream("<?= $active_stream ?>");
<?php endif; ?>

function playChannel(e, encoded, name) {
    e.preventDefault();
    loadStream(atob(encoded));
    const np = document.getElementById('now-playing');
    if (np) np.querySelector('span').textContent = name;
    document.querySelectorAll('.channel-card').forEach(c => c.classList.remove('active'));
    e.currentTarget.classList.add('active');
    history.replaceState(null, '', '?play=' + encoded);
    if (window.innerWidth < 640) window.scrollTo({ top: 0, behavior: 'smooth' });
}

const searchInput = document.getElementById('search-input');
searchInput.addEventListener('input', filterChannels);

let activeCat = 'all';
document.querySelectorAll('.cat-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        activeCat = btn.dataset.cat;
        document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active-cat'));
        btn.classList.add('active-cat');
        filterChannels();
    });
});

function filterChannels() {
    const q = searchInput.value.toLowerCase().trim();
    const cards = document.querySelectorAll('#channel-grid .channel-card');
    let visible = 0;
    cards.forEach(card => {
        const show = card.dataset.name.includes(q) &&
                     (activeCat === 'all' || card.dataset.cat === activeCat.toLowerCase());
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('no-result').classList.toggle('hidden', visible > 0);
}
</script>
</body>
</html>
