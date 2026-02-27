# Cross-Module API Testing Setup

## Test Environment

**Project**: test-razy-cli  
**Distribution**: mysite  
**Framework**: Razy v0.5.4  

## Test Modules Setup

### Module 1: vendor/user (API Provider)

**Location**: `sites/mysite/vendor/user/`

**Exposed APIs**:
1. `get_user($userId)` - Returns user object by ID
2. `get_user_email($userId)` - Returns email address
3. `authenticate($username, $password)` - Authenticates user

**Test Data**:
```
User IDs: 1, 2, 3
  1 = John Doe (admin)
  2 = Jane Smith (user)
  3 = Bob Johnson (user)

Credentials:
  john / pass123
  jane / pass456
  bob / pass789
```

### Module 2: vendor/profile (API Consumer)

**Location**: `sites/mysite/vendor/profile/`

**Routes Available**:
1. `/profile/view/[userId]` - View user profile (calls get_user)
2. `/profile/email/[userId]` - Get user email (calls get_user_email)
3. `/profile/login/[username]/[password]` - Login (calls authenticate)
4. `/profile/full_profile/[userId]` - Full profile (calls multiple APIs)

## Test Cases

### Test 1: View User Profile

**Route**: `GET /profile/view/1`

**Expected Response**:
```json
{
    "status": "success",
    "calling_module": "vendor/profile",
    "api_called": "vendor/user->get_user",
    "user": {
        "id": 1,
        "name": "John Doe",
        "role": "admin"
    },
    "timestamp": "2026-02-09 HH:MM:SS"
}
```

**API Call Stack**:
```
profile.view.php
  ??$this->api('vendor/user')->get_user(1)
    ??user/api/get_user.php
      ??Returns user object
```

---

### Test 2: Get User Email

**Route**: `GET /profile/email/2`

**Expected Response**:
```json
{
    "status": "success",
    "calling_module": "vendor/profile",
    "api_called": "vendor/user->get_user_email",
    "user_id": 2,
    "email": "jane@example.com",
    "timestamp": "2026-02-09 HH:MM:SS"
}
```

**API Call Stack**:
```
profile.email.php
  ??$this->api('vendor/user')->get_user_email(2)
    ??user/api/get_user_email.php
      ??Returns email data
```

---

### Test 3: Authenticate User

**Route**: `GET /profile/login/john/pass123`

**Expected Response (Success)**:
```json
{
    "status": "success",
    "calling_module": "vendor/profile",
    "api_called": "vendor/user->authenticate",
    "message": "User 'john' authenticated successfully",
    "authenticated": true,
    "user_id": 1,
    "timestamp": "2026-02-09 HH:MM:SS"
}
```

**Expected Response (Failure)**:
```json
{
    "status": "error",
    "calling_module": "vendor/profile",
    "message": "Invalid password",
    "authenticated": false,
    "timestamp": "2026-02-09 HH:MM:SS"
}
```

**API Call Stack**:
```
profile.login.php
  ??$this->api('vendor/user')->authenticate('john', 'pass123')
    ??user/api/authenticate.php
      ??Returns auth result
```

---

### Test 4: Full User Profile (Multiple API Calls)

**Route**: `GET /profile/full_profile/1`

**Expected Response**:
```json
{
    "status": "success",
    "calling_module": "vendor/profile",
    "apis_called": [
        "vendor/user->get_user",
        "vendor/user->get_user_email"
    ],
    "profile": {
        "id": 1,
        "name": "John Doe",
        "role": "admin",
        "email": "john@example.com"
    },
    "timestamp": "2026-02-09 HH:MM:SS"
}
```

**API Call Stack**:
```
profile.full_profile.php
  ??$this->api('vendor/user')->get_user(1)
    ??user/api/get_user.php
      ??Returns user object
  
  ??$this->api('vendor/user')->get_user_email(1)
    ??user/api/get_user_email.php
      ??Returns email
  
  ??Combines both results
```

---

## Configuration Verification

### dist.php Configuration

Check `sites/mysite/dist.php` includes both modules:

```php
'modules' => [
    '*' => [
        'vendor/user' => 'default',      // API provider
        'vendor/profile' => 'default',   // API consumer
    ],
],
```

### Module Dependencies

Check `vendor/profile/default/package.php` declares dependency:

```php
'required_modules' => [
    'vendor/user',
],
```

This ensures user module loads **before** profile module.

---

## Testing Flow

```
1. Distribution loads in this order:
   ?œâ??€ vendor/user (required module)
   ??  ?”â??€ Registers APIs: get_user, get_user_email, authenticate
   ?”â??€ vendor/profile (depends on vendor/user)
       ?”â??€ Registers routes: view, email, login, full_profile

2. When request comes to /profile/view/1:
   ?œâ??€ Route matched to profile.view.php
   ?œâ??€ Handler calls: $this->api('vendor/user')->get_user(1)
   ?œâ??€ API found in loaded vendor/user module
   ?œâ??€ Executes: user/api/get_user.php
   ?”â??€ Returns: user object

3. Response sent to client
```

---

## Error Scenarios

### Scenario 1: Invalid User ID

**Route**: `GET /profile/view/999`

**Expected Response**:
```json
{
    "status": "error",
    "message": "User ID 999 not found"
}
```

### Scenario 2: Wrong Password

**Route**: `GET /profile/login/john/wrongpass`

**Expected Response**:
```json
{
    "status": "error",
    "message": "Invalid password",
    "authenticated": false
}
```

### Scenario 3: User Module Not Available

(If vendor/user module fails to load)

**Expected Response**:
```json
{
    "status": "error",
    "message": "Module 'vendor/user' not found or API not available"
}
```

---

## Key Observations

### ??Working Pattern

1. **Separation**: Each module has single responsibility
2. **Dependency Declaration**: Profile declares it needs User
3. **Ordered Loading**: Dependencies load first
4. **API Contract**: Clear interface between modules
5. **Error Handling**: Graceful error responses

### ??Benefits Demonstrated

1. **Reusability**: User APIs can be called by any module
2. **Modularity**: Changes in user logic don't affect profile
3. **Testability**: Each module can be tested independently
4. **Scalability**: Easy to add new modules using user APIs

---

## File Structure Verification

```
sites/mysite/
?œâ??€ vendor/
??  ?œâ??€ user/
??  ??  ?œâ??€ module.php ..................... ??
??  ??  ?”â??€ default/
??  ??      ?œâ??€ package.php ............... ??
??  ??      ?œâ??€ controller/
??  ??      ??  ?”â??€ user.php ............. ??
??  ??      ?”â??€ api/
??  ??          ?œâ??€ get_user.php ......... ??
??  ??          ?œâ??€ get_user_email.php .. ??
??  ??          ?”â??€ authenticate.php .... ??
??  ??
??  ?”â??€ profile/
??      ?œâ??€ module.php ..................... ??
??      ?”â??€ default/
??          ?œâ??€ package.php ............... ??
??          ?”â??€ controller/
??              ?œâ??€ profile.php .......... ??
??              ?œâ??€ profile.view.php .... ??
??              ?œâ??€ profile.email.php ... ??
??              ?œâ??€ profile.login.php ... ??
??              ?”â??€ profile.full_profile.php ... ??
```

---

## Summary

This test setup demonstrates:

??**API Exposure**: User module exposes 3 APIs  
??**API Consumption**: Profile module calls user APIs  
??**Dependency Management**: Profile depends on user  
??**Error Handling**: Graceful error responses  
??**Multiple API Calls**: Full profile calls 2 APIs  
??**Real-World Pattern**: Production-like example  

The setup is ready for HTTP testing via:
- Razy CLI application
- Web server integration
- PHPUnit integration tests
