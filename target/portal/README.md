# CTF Target Portal

A small PHP app that runs on each student's Target VM. It is the UI the
student opens in a browser to:
- Activate the VM with a one-time code from the CTF Server.
- Submit a Task Token to start a challenge.
- Reset a challenge after giving up.

The Portal itself holds **no secrets** — every action proxies to the
local Python Agent on `127.0.0.1:8787`, which has the device token.

## Trust boundary

- Portal binds to `127.0.0.1:80` only. Apache config denies non-loopback.
- Portal's PHP runs with `disable_functions = exec,passthru,popen,proc_open,shell_exec,system`
  + `open_basedir = /var/www/ctf-target-portal:/tmp`.
- Portal code MUST NOT use any of the disabled functions. Tests grep
  the codebase to enforce this.

## Layout

```
target/portal/
├── public/index.php      — front controller (Apache rewrites to here)
├── src/
│   ├── Router.php         — minimal route → controller dispatcher
│   ├── View.php           — plain PHP include renderer
│   ├── AgentClient.php    — curl client to 127.0.0.1:8787
│   └── PortalController.php — /, /activate, /task, /sync, /reset
├── views/
│   ├── layout.php          — terminal-style chrome (ink/brass/drafting)
│   ├── home.php            — status + actions
│   ├── activate.php        — paste activation code
│   └── task.php            — paste task token + show entrypoint
├── apache/ctf-portal.conf  — vhost (loopback-only, disable_functions)
└── README.md
```

## Install

After `target/install.sh` (which provisions the Python Agent):

```bash
sudo ./target/install-portal.sh
```

Open `http://127.0.0.1/` in the VM's browser.

## Routes

| Method | Path       | Purpose                                  |
|--------|------------|------------------------------------------|
| GET    | `/`        | Status (Agent reachability, credentials) |
| GET    | `/activate` | Show activation form                    |
| POST   | `/activate` | Submit code → Agent /activate            |
| POST   | `/sync`    | Manually trigger Agent /sync             |
| GET    | `/task`    | Show task-token form                     |
| POST   | `/task`    | Submit token → Agent /task → entrypoint  |
| POST   | `/reset`   | Submit challenge_id → Agent /reset       |

## Tests

The Portal is plain PHP with no test framework dependency. Smoke tests
live in `tests/test_static.py` (Python, runs `php -l` over every file +
greps for banned function names + forbidden patterns). Run:

```bash
python3 -m pytest tests/ -v
```

For an end-to-end test against a live Agent, see the e2e suite in
`tests/test_portal_e2e.py` (requires both Server and Agent reachable).
