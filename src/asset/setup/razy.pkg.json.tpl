{
  "package_name": "my-app",
  "version": "1.0.0",
  "description": "My standalone package",
  "mode": "exec",
  "strict": false,
  "on_depend": [
    {"package": "db-setup", "wait": "complete"},
    {"package": "cache-service", "wait": "healthcheck"},
    {"package": "shared-lib", "wait": "load"}
  ],
  "healthcheck": {
    "url": "",
    "interval": 2,
    "timeout": 30,
    "start_period": 5
  },
  "prerequisite": {}
}
