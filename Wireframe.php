<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wireframe</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: white;
        }
        #sidebar {
            width: 150px;
            background: #f0f0f0;
            color: black;
            padding: 20px;
            float: left;
            min-height: 100vh;
        }
        #sidebar h2 {
            margin-top: 0;
        }
        #sidebar p {
            margin: 0 0 20px;
        }
        .item-toggle {
            margin-bottom: 10px;
            padding: 10px;
            background: #e0e0e0;
            cursor: pointer;
        }
        .item-toggle.active {
            background: #d0d0d0;
        }
        .item-toggle span {
            display: block;
        }
        #main {
            margin-left: 190px;
            padding: 20px;
        }
        #main h1 {
            margin-top: 0;
        }
        #stack {
            margin-top: 20px;
        }
        .stack-item {
            position: relative;
            background: white;
            border: 1px solid #ccc;
            padding: 15px;
            margin-bottom: 10px;
        }
        .stack-item h3 {
            margin: 0 0 10px;
        }
        .stack-item .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        .stack-item .bookmark-btn {
            background: none;
            border: 1px solid #0066cc;
            color: #0066cc;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.9rem;
            cursor: pointer;
        }
        .stack-item .bookmark-btn:hover {
            background: #f0f8ff;
        }
        .stack-item p {
            margin: 0;
        }
        .stack-item ul {
            margin: 10px 0 0;
            padding-left: 20px;
        }
        .stack-item ul li {
            margin-bottom: 6px;
        }
        .stack-item a {
            color: #0066cc;
            text-decoration: none;
        }
        .stack-item ul li a {
            color: #0066cc;
            text-decoration: none;
        }
        .stack-item ul li a:hover,
        .stack-item a:hover {
            text-decoration: underline;
        }
        .player-stats-card {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 18px;
            padding: 16px 0 0;
            align-items: start;
        }
        .player-photo {
            width: 150px;
            min-height: 150px;
            background: #e8e8e8;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #777;
            font-size: 0.95rem;
            overflow: hidden;
        }
        .player-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .player-stats-content {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .player-stats-info {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px 40px;
            align-items: start;
        }
        .player-stats-info p {
            margin: 4px 0;
            font-size: 0.97rem;
        }
        .player-stats-info strong {
            display: inline-block;
            min-width: 110px;
        }
        .player-stats-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        .player-stats-table th,
        .player-stats-table td {
            border: 1px solid #ddd;
            padding: 10px 12px;
            text-align: left;
            font-size: 0.95rem;
        }
        .player-stats-table th {
            background: #f7f7f7;
            font-weight: 600;
        }
        .player-stats-table tbody tr:hover {
            background: #fbfbfb;
        }
        .search-box {
            display: flex;
            gap: 6px;
            margin-bottom: 12px;
        }
        .search-box input {
            flex: 1;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .search-box button {
            padding: 8px 14px;
            border: 1px solid #999;
            background: #f0f0f0;
            cursor: pointer;
            border-radius: 4px;
        }
        .search-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 12px;
        }
        .search-filters label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }
        .search-results {
            margin-top: 12px;
            padding: 10px;
            border: 1px solid #ccc;
            background: #f9f9f9;
            border-radius: 4px;
            min-height: 60px;
            color: #999;
            font-size: 0.95rem;
        }
    </style>
</head>
<body>
    <aside id="sidebar">
        <h2>Elements</h2>
        <p>Use the sidebar to show or hide elements in the explorer.</p>
        <div id="controls"></div>
    </aside>
    <main id="main">
        <h1>Cricket Stats Explorer</h1>
        <div id="stack"></div>
    </main>
    <script>
        const elements = [
            {
                id: 'search',
                title: 'Search',
                type: 'search',
                placeholder: 'Search players, teams, competitions, seasons, matches...',
                filters: ['Players', 'Teams',  'Competitions', 'Seasons', 'Matches']
            },
            {
                id: 'player-stats',
                title: 'Player Stats',
                description: 'stats',
                player: {
                    name: '—',
                    dateOfBirth: '—',
                    nationality: '—',
                    team: '—',
                    role: '—',
                    battingStyle: '—',
                    photo: '',
                    stats: [
                        { format: '—', matches: '—', runs: '—', average: '—', strikeRate: '—' },
                        { format: '—', matches: '—', runs: '—', average: '—', strikeRate: '—' },
                        { format: '—', matches: '—', runs: '—', average: '—', strikeRate: '—' }
                    ]
                }
            },
            {
                id: 'team',
                title: 'Team Overview',
                description: 'team',
                team: {
                    name: '—',
                    country: '—',
                    captain: '—',
                    homeGround: '—',
                    players: [
                        '—',
                        '—',
                        '—'
                    ]
                }
            },
            {
                id: 'competition',
                title: 'Competition Overview',
                description: 'competition',
                competition: {
                    name: '—',
                    format: '—',
                    currentSeason: '—',
                    seasons: [
                        '—',
                        '—',
                        '—'
                    ]
                }
            },
            {
                id: 'season-overview',
                title: 'Season Overview',
                description: 'season',
                season: {
                    name: '—',
                    start: '—',
                    end: '—',
                    matches: [
                        '—',
                        '—',
                        '—'
                    ]
                }
            },
            {
                id: 'match-summary',
                title: 'Match Summary',
                description: 'match',
                match: {
                    name: '—',
                    season: '—',
                    date: '—',
                    venue: '—',
                    teamA: '—',
                    teamB: '—',
                    winningTeam: '—',
                    winType: '—',
                    winMargin: '—',
                    winMethod: '—',
                    matchType: '—',
                    tossWinner: '—',
                    tossDecision: '—'
                }
            },
            {
                id: 'bookmarks',
                title: 'Bookmarks',
                description: 'bookmarks',
                bookmarks: {
                    players: [
                        '—',
                        '—',
                        '—'
                    ],
                    teams: [
                        '—',
                        '—',
                        '—'
                    ],
                    competitions: [
                        '—',
                        '—',
                        '—'
                    ],
                    seasons: [
                        '—',
                        '—',
                        '—'
                    ],
                    matches: [
                        '—',
                        '—',
                        '—'
                    ]
                }
            },
            {
                id: 'import-export',
                title: 'Import & Export',
                description: 'Import or export data.'
            }
        ];

        const STORAGE_KEY = 'cricket-explorer-active';
        let active = new Set();
        
        // Load from localStorage or use defaults
        function loadActiveElements() {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved) {
                try {
                    active = new Set(JSON.parse(saved));
                } catch (e) {
                    active = new Set(elements.map(element => element.id));
                }
            } else {
                active = new Set(elements.map(element => element.id));
            }
        }
        
        // Save active state to localStorage
        function saveActiveElements() {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(Array.from(active)));
        }
        
        loadActiveElements();
        const controls = document.getElementById('controls');
        const stack = document.getElementById('stack');

        function renderControls() {
            controls.innerHTML = '';
            elements.forEach(element => {
                const item = document.createElement('div');
                item.className = 'item-toggle' + (active.has(element.id) ? ' active' : '');
                item.dataset.id = element.id;
                item.innerHTML = '<span>' + element.title + '</span>';
                item.addEventListener('click', () => {
                    if (active.has(element.id)) {
                        active.delete(element.id);
                    } else {
                        active.add(element.id);
                    }
                    saveActiveElements();
                    render();
                });
                controls.appendChild(item);
            });
        }

        function renderElementContent(element) {
            if (element.type === 'search') {
                const filters = element.filters
                    .map(filter => '<label><input type="checkbox" checked /> ' + filter + '</label>')
                    .join('');
                return '<div class="search-box"><input type="text" placeholder="' + element.placeholder + '" /><button>Search</button></div><div class="search-filters">' + filters + '</div><div class="search-results">No search performed. Enter a query to see results.</div>';
            }
            if (element.id === 'player-stats' && element.player) {
                const player = element.player;
                const rows = player.stats.map(stat =>
                    '<tr>' +
                        '<td>' + stat.format + '</td>' +
                        '<td>' + stat.matches + '</td>' +
                        '<td>' + stat.runs + '</td>' +
                        '<td>' + stat.average + '</td>' +
                        '<td>' + stat.strikeRate + '</td>' +
                    '</tr>'
                ).join('');
                const photo = player.photo
                    ? '<div class="player-photo"><img src="' + player.photo + '" alt="Player photo" /></div>'
                    : '<div class="player-photo">Photo</div>';
                return '<div class="player-stats-card">' +
                    photo +
                    '<div class="player-stats-content">' +
                        '<div class="player-stats-info">' +
                            '<p><strong>Name:</strong> ' + player.name + '</p>' +
                            '<p><strong>Date of Birth:</strong> ' + player.dateOfBirth + '</p>' +
                            '<p><strong>Nationality:</strong> ' + player.nationality + '</p>' +
                            '<p><strong>Team:</strong> ' + player.team + '</p>' +
                            '<p><strong>Role:</strong> ' + player.role + '</p>' +
                            '<p><strong>Batting Style:</strong> ' + player.battingStyle + '</p>' +
                        '</div>' +
                        '<table class="player-stats-table">' +
                            '<thead><tr><th>Format</th><th>Matches</th><th>Runs</th><th>Average</th><th>Strike Rate</th></tr></thead>' +
                            '<tbody>' + rows + '</tbody>' +
                        '</table>' +
                    '</div>' +
                '</div>';
            }
            if (element.id === 'team' && element.team) {
                const team = element.team;
                const players = team.players && team.players.length
                    ? '<ul>' + team.players.map(player => '<li><a href="#">' + player + '</a></li>').join('') + '</ul>'
                    : '<p>No players available for this team.</p>';
                return '<div>' +
                    '<p><strong>Team Name:</strong> ' + team.name + '</p>' +
                    '<p><strong>Country:</strong> ' + team.country + '</p>' +
                    '<p><strong>Captain:</strong> ' + team.captain + '</p>' +
                    '<p><strong>Home Ground:</strong> ' + team.homeGround + '</p>' +
                    '<h4>Players</h4>' +
                    players +
                    '</div>';
            }
            if (element.id === 'competition' && element.competition) {
                const competition = element.competition;
                const seasons = competition.seasons && competition.seasons.length
                    ? '<ul>' + competition.seasons.map(season => '<li><a href="#">' + season + '</a></li>').join('') + '</ul>'
                    : '<p>No seasons available for this competition.</p>';
                return '<div>' +
                    '<p><strong>Competition Name:</strong> ' + competition.name + '</p>' +
                    '<p><strong>Format:</strong> ' + competition.format + '</p>' +
                    '<p><strong>Current Season:</strong> ' + competition.currentSeason + '</p>' +
                    '<h4>Seasons</h4>' +
                    seasons +
                    '</div>';
            }
            if (element.id === 'season-overview' && element.season) {
                const season = element.season;
                const matches = season.matches && season.matches.length
                    ? '<ul>' + season.matches.map(match => '<li><a href="#">' + match + '</a></li>').join('') + '</ul>'
                    : '<p>No matches available for this season.</p>';
                return '<div>' +
                    '<p><strong>Season Name:</strong> ' + season.name + '</p>' +
                    '<p><strong>Start Date:</strong> ' + season.start + '</p>' +
                    '<p><strong>End Date:</strong> ' + season.end + '</p>' +
                    '<h4>Matches</h4>' +
                    matches +
                    '</div>';
            }
            if (element.id === 'match-summary' && element.match) {
                const match = element.match;
                return '<div>' +
                    '<p><strong>Match Name:</strong> ' + match.name + '</p>' +
                    '<p><strong>Season:</strong> <a href="#">' + match.season + '</a></p>' +
                    '<p><strong>Date:</strong> ' + match.date + '</p>' +
                    '<p><strong>Venue:</strong> ' + match.venue + '</p>' +
                    '<p><strong>Teams:</strong> <a href="#">' + match.teamA + '</a> vs <a href="#">' + match.teamB + '</a></p>' +
                    '<p><strong>Winning Team:</strong> <a href="#">' + match.winningTeam + '</a></p>' +
                    '<p><strong>Win Type:</strong> ' + match.winType + '</p>' +
                    '<p><strong>Win Margin:</strong> ' + match.winMargin + '</p>' +
                    '<p><strong>Win Method:</strong> ' + match.winMethod + '</p>' +
                    '<p><strong>Match Type:</strong> ' + match.matchType + '</p>' +
                    '<p><strong>Toss Winner:</strong> <a href="#">' + match.tossWinner + '</a></p>' +
                    '<p><strong>Toss Decision:</strong> ' + match.tossDecision + '</p>' +
                    '</div>';
            }
            if (element.id === 'import-export') {
                return '<div>' +
                    '<label for="import-file"><strong>Import file</strong></label>' +
                    '<input id="import-file" type="file" accept=".json,.csv" style="display:block; margin: 10px 0;" />' +
                    '<p><strong>Export data</strong></p>' +
                    '<button style="padding: 8px 14px; border: 1px solid #999; background: #f0f0f0; cursor: pointer; border-radius: 4px;">Export</button>' +
                    '</div>';
            }
            if (element.id === 'bookmarks' && element.bookmarks) {
                const bookmarks = element.bookmarks;
                const players = bookmarks.players && bookmarks.players.length
                    ? '<ul>' + bookmarks.players.map(item => '<li><a href="#">' + item + '</a></li>').join('') + '</ul>'
                    : '<p>No players bookmarked.</p>';
                const teams = bookmarks.teams && bookmarks.teams.length
                    ? '<ul>' + bookmarks.teams.map(item => '<li><a href="#">' + item + '</a></li>').join('') + '</ul>'
                    : '<p>No teams bookmarked.</p>';
                const competitions = bookmarks.competitions && bookmarks.competitions.length
                    ? '<ul>' + bookmarks.competitions.map(item => '<li><a href="#">' + item + '</a></li>').join('') + '</ul>'
                    : '<p>No competitions bookmarked.</p>';
                const seasons = bookmarks.seasons && bookmarks.seasons.length
                    ? '<ul>' + bookmarks.seasons.map(item => '<li><a href="#">' + item + '</a></li>').join('') + '</ul>'
                    : '<p>No seasons bookmarked.</p>';
                const matches = bookmarks.matches && bookmarks.matches.length
                    ? '<ul>' + bookmarks.matches.map(item => '<li><a href="#">' + item + '</a></li>').join('') + '</ul>'
                    : '<p>No matches bookmarked.</p>';
                return '<div>' +
                    '<h4>Players</h4>' + players +
                    '<h4>Teams</h4>' + teams +
                    '<h4>Competitions</h4>' + competitions +
                    '<h4>Seasons</h4>' + seasons +
                    '<h4>Matches</h4>' + matches +
                    '</div>';
            }
            
        }

        function renderStack() {
            stack.innerHTML = '';
            const visible = elements.filter(element => active.has(element.id));
            const bookmarkable = new Set(['player-stats', 'team', 'competition', 'season-overview', 'match-summary']);
            visible.forEach(element => {
                const card = document.createElement('div');
                card.className = 'stack-item';
                const header = '<div class="card-header"><h3>' + element.title + '</h3>' +
                    (bookmarkable.has(element.id) ? '<button class="bookmark-btn">★ Bookmark</button>' : '') +
                    '</div>';
                card.innerHTML = header + renderElementContent(element);
                stack.appendChild(card);
            });
            if (visible.length === 0) {
                stack.innerHTML = '<p>No elements selected. Toggle items from the sidebar.</p>';
            }
        }

        function render() {
            renderControls();
            renderStack();
        }

        render();
    </script>
</body>
</html>