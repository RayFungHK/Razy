# Release Notes - Razy v0.5.4

Comprehensive release notes for Razy v0.5.4 with features, improvements, and migration guidance.

**Release Date**: February 9, 2026 | **Type**: Feature Release | **Stability**: Stable

---

### Release Highlights

This release focuses on **developer tooling, async workflows, and streaming support**. Razy v0.5.4 adds new CLI tooling and introduces the first iteration of the Thread system and SSE streaming.

---

### Major Features

### 0. Interactive App Shell (runapp)
**Run distributors interactively without sites.inc.php configuration**

- ✅ `php Razy.phar runapp <dist_code[@tag]>` - Start interactive shell
- ✅ Bash-like prompt: `[distCode]>` or `[distCode@tag]>`
- ✅ Built-in commands: `help`, `info`, `routes`, `modules`, `api`, `run`, `call`, `exit`
- ✅ Pipe support for scripting: `@("routes", "exit") | php Razy.phar runapp appdemo`
- ✅ Cross-platform: Windows PowerShell UTF-8 BOM handling

See [docs/wiki/OPTIMIZATION-SUGGESTIONS.md](../wiki/OPTIMIZATION-SUGGESTIONS.md#cli-system-issues)

---

### Major Features

### 1. GitHub Module Installer
**Install modules directly from GitHub repositories**

- ✅ Public and private repository support
- ✅ Branch or release installation
- ✅ Real-time download progress
- ✅ Distributor module installation
- ✅ CLI command: `php Razy.phar install owner/repo`

See [docs/guides/GITHUB-INSTALLER.md](../guides/GITHUB-INSTALLER.md)

---

### 2. Thread System (Initial)
**In-process tasks + process backend for async jobs**

- ✅ `Thread` and `ThreadManager` core APIs
- ✅ Process backend with concurrency control
- ✅ `Agent::thread()` accessor for modules

See [docs/guides/THREAD-SYSTEM.md](../guides/THREAD-SYSTEM.md)

---

### 3. SSE Streaming Helper
**Server-Sent Events helper with proxy mode**

- ✅ Stream SSE responses with a small helper
- ✅ Proxy upstream SSE endpoints (LLM streams)

See [src/library/Razy/SSE.php](src/library/Razy/SSE.php)

### 4. Cross-Distributor Internal Bridge (Initial)
**Internal HTTP endpoint for safe distributor-to-distributor calls**

- ✅ Allowlist-based access in `dist.php`
- ✅ Optional HMAC signature
- ✅ Executes local module API commands

See [docs/guides/CROSS-DISTRIBUTOR-COMMUNICATION.md](../guides/CROSS-DISTRIBUTOR-COMMUNICATION.md)

### 5. Internal API Execution with Fallback Mechanism
**Smart fallback for cross-distributor calls on restricted hosts**

- ✅ Automatic detection of available execution methods
- ✅ CLI Process Isolation (safest - separate PHP process)
- ✅ HTTP Bridge (fallback - same process)
- ✅ Direct Execution (last resort - in-process)
- ✅ Solves class namespace conflicts from different Composer versions
- ✅ Works on hosts with disabled functions (proc_open, allow_url_fopen)
- ✅ Comprehensive warnings for unsafe execution paths

See [docs/guides/INTERNAL-API-FALLBACK-AND-ISOLATION.md](../guides/INTERNAL-API-FALLBACK-AND-ISOLATION.md)

---

### 6. LLM Assistant Documentation System
**AI-friendly auto-generated documentation for your codebase**

- ✅ CLI command: `php Razy.phar generate-llm-docs`
- ✅ Root-level framework context (`LLM-CAS.md`)
- ✅ Distribution-level context (`llm-cas/{dist_code}.md`)
- ✅ Module-level context (`llm-cas/{dist_code}/{module}.md`)
- ✅ Static analysis of Controller.php (no initialization)
- ✅ Extraction of API commands and lifecycle events
- ✅ @llm prompt comments in PHP code
- ✅ {#llm prompt} tags in TPL templates
- ✅ Automatic removal of tags from HTML output
- ✅ Module dependency graphs and communication patterns

📖 See [LLM-CAS.md](../../LLM-CAS.md)

---

### 7. Async SMTP for Mailer
**Non-blocking SMTP send via ThreadManager**

- ✅ `Mailer::sendAsync()` dispatches SMTP send in background
- ✅ Optional `await()` for result collection

See [docs/usage/Razy.Mailer.md](docs/usage/Razy.Mailer.md)

---

## 🔄 Upgrade Guide

### From v0.5.3 to v0.5.4

**No breaking changes!** All changes are additive.

#### Step 1: Update Razy.phar
```bash
php -d phar.readonly=0 build.php
```

#### Step 2: Review new docs
- Read [docs/guides/THREAD-SYSTEM.md](../guides/THREAD-SYSTEM.md)
- Review [docs/usage/Razy.Mailer.md](../usage/Razy.Mailer.md)
- Check [CHANGELOG.md](CHANGELOG.md) for complete changes

---

## 🎓 What This Means for Your Project

### For Individual Developers
- ✅ **Better code quality** with automated enforcement
- ✅ **Confidence in changes** via unit tests
- ✅ **Faster development** with consistent patterns
- ✅ **Professional standards** matching industry best practices

### For Teams
- ✅ **Consistent code style** across all team members
- ✅ **Faster code reviews** - focus on logic, not style
- ✅ **Fewer merge conflicts** from formatting differences
- ✅ **Easier onboarding** - new developers follow clear standards

### For Production
- ✅ **Fewer bugs** caught by tests before deployment
- ✅ **Easier maintenance** with clean, consistent code
- ✅ **Better reliability** with comprehensive test coverage
- ✅ **Enterprise-ready** quality standards

---

## 📋 Files Changed

### New Files
- `src/library/Razy/Thread.php` - Thread entity
- `src/library/Razy/ThreadManager.php` - Thread manager and process backend
- `src/library/Razy/SSE.php` - Server-Sent Events helper
- `docs/guides/THREAD-SYSTEM.md` - Thread system overview

### Modified Files
- `src/library/Razy/Mailer.php` - Added async SMTP support
- `docs/usage/Razy.Mailer.md` - Added async SMTP usage
- `CHANGELOG.md` - Updated v0.5.4 notes

---

## 🔮 What's Next?

### Short Term (v0.5.x)
- Expand Thread system with native backend options
- Add more streaming utilities and LLM helpers
- Improve Mailer diagnostics for async sends

### Long Term (v1.0.0)
- Production hardening
- Performance optimizations
- Comprehensive documentation

See [CHANGELOG.md](CHANGELOG.md) for roadmap details.

---

## 📞 Support

- **Documentation**: [docs/documentation/DOCS-README.md](../documentation/DOCS-README.md)
- **Issues**: https://github.com/rayfunghk/razy/issues
- **Email**: hello@rayfung.hk

---

## 🙏 Acknowledgments

Special thanks to:
- **PHP-FIG** for PSR standards
- **PHPUnit** team for testing framework
- **PHP CS Fixer** team for code quality tools
- All contributors and users of Razy

---

## 📜 License

MIT License - see [LICENSE](LICENSE) file for details.

---

**🎉 Happy Coding with Razy v0.5.4!**

*Released with ❤️ by Ray Fung - February 16, 2026*
