# Contributing to Razy Framework

Thank you for your interest in contributing to Razy! This guide will help you get started.

## Getting Started

1. **Fork** the repository on GitHub
2. **Clone** your fork locally:
   ```bash
   git clone https://github.com/YOUR_USERNAME/Razy.git
   cd Razy
   ```
3. **Install dependencies**:
   ```bash
   composer install
   ```
4. **Run the test suite** to verify your setup:
   ```bash
   composer test
   ```

## Development Workflow

1. Create a **feature branch** from `main`:
   ```bash
   git checkout -b feature/your-feature-name
   ```
2. Make your changes
3. Run **tests and code style checks**:
   ```bash
   composer quality    # Runs tests + PSR-12 check
   ```
4. **Commit** with a clear message:
   ```bash
   git commit -m "Add: brief description of the change"
   ```
5. **Push** and open a Pull Request

## Coding Standards

- **PSR-4** autoloading for all classes under `src/library/`
- **PSR-12** code style — enforced by PHP CS Fixer:
  ```bash
  composer cs-check   # Check without modifying
  composer cs-fix     # Auto-fix style issues
  ```
- Type declarations on all method parameters and return types
- PHPDoc blocks for public methods

## Testing

- All new features **must** include tests
- Place test files in `tests/` with the suffix `Test.php`
- Tests must pass before a PR will be reviewed:
  ```bash
  composer test
  ```
- Aim for meaningful assertions, not just line coverage

## Commit Message Format

Use a prefix to categorize your commit:

| Prefix | Usage |
|--------|-------|
| `Add:` | New feature or class |
| `Fix:` | Bug fix |
| `Refactor:` | Code restructuring without behavior change |
| `Docs:` | Documentation only |
| `Test:` | Adding or updating tests |
| `Chore:` | Build, CI, or tooling changes |

## Pull Request Guidelines

- Keep PRs focused — one feature or fix per PR
- Reference related issues (e.g., `Closes #42`)
- Ensure all CI checks pass
- Include test coverage for new code
- Update documentation if your change affects public API

## Reporting Issues

- Use the GitHub issue templates
- Include PHP version, OS, and steps to reproduce
- Attach relevant error messages or logs

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](CODE_OF_CONDUCT.md). By participating, you are expected to uphold this code.

## License

By contributing, you agree that your contributions will be licensed under the [MIT License](LICENSE).
