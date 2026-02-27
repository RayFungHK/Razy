# Quick Start: Testing Cross-Module API Calling

Test the cross-module API implementation in 5 minutes. Demonstrates vendor/user and vendor/profile modules.

**Duration**: ~5 minutes | **Level**: Beginner

---

## Table of Contents

1. [Setup](#setup)
2. [Test Cases](#test-cases)
3. [Verification](#verification)

---

### Setup

#### What You Have
- **vendor/user** module: Provides 3 APIs (get_user, get_user_email, authenticate)
- **vendor/profile** module: Calls user APIs through 4 routes
- **dist.php**: Both modules configured and loaded

#### How It Works
```
HTTP Request
    ↓
profile.view.php (route handler)
    ↓
$this->api('vendor/user')->get_user($id) (API call)
    ↓
user/api/get_user.php (API implementation)
    ↓
HTTP Response (JSON)
```

---

### Test Cases

#### Test 1: View User by ID

**URL**: `http://localhost/profile/view/1`  
**Method**: GET  
**Expected Status**: 200

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

✅ **What This Tests**:
- vendor/profile can call vendor/user's API
- get_user API returns correct user object
- JSON response formatting

---

### Test 2: Get User Email

**URL**: `http://localhost/profile/email/2`  
**Method**: GET

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

✅ **What This Tests**:
- Second API from user module works
- Email lookup by user ID

---

### Test 3: Authenticate User

**URL**: `http://localhost/profile/login/john/pass123`  
**Method**: GET

**Expected Response** (Success):
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

**Alternative - Wrong Password**:
```bash
# Try: http://localhost/profile/login/john/wrongpass
```

```json
{
    "status": "error",
    "message": "Invalid password",
    "authenticated": false
}
```

✅ **What This Tests**:
- Authentication API works
- Credential validation
- Error handling

---

### Test 4: Full Profile (Multiple API Calls) ⭐ MOST INTERESTING

**URL**: `http://localhost/profile/full_profile/1`  
**Method**: GET

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

✅ **What This Tests**:
- Multiple API calls in sequence
- Data combining from 2 APIs
- Complex response building

---

## Test Data Reference

### Users
```
ID | Name        | Role  | Email
1  | John Doe    | admin | john@example.com
2  | Jane Smith  | user  | jane@example.com
3  | Bob Johnson | user  | bob@example.com
```

### Credentials
```
Username | Password | User ID
john     | pass123  | 1
jane     | pass456  | 2
bob      | pass789  | 3
```

**Try These Logins**:
- ✅ `/profile/login/john/pass123` - Success
- ✅ `/profile/login/jane/pass456` - Success
- ❌ `/profile/login/john/wrongpass` - Error (wrong password)
- ❌ `/profile/login/unknown/pass123` - Error (unknown user)

---

## Error Scenarios

### Invalid User ID
**URL**: `http://localhost/profile/view/999`

```json
{
    "status": "error",
    "message": "User ID 999 not found"
}
```

### Unknown User Login
**URL**: `http://localhost/profile/login/unknown/pass123`

```json
{
    "status": "error",
    "message": "User not found",
    "authenticated": false
}
```

---

## Testing with cURL

**Get user profile**:
```bash
curl http://localhost/profile/view/1
```

**Get email**:
```bash
curl http://localhost/profile/email/2
```

**Test login**:
```bash
curl http://localhost/profile/login/john/pass123
```

**Get full profile**:
```bash
curl http://localhost/profile/full_profile/1
```

---

## Testing with Postman/Thunder Client

1. Create GET request
2. URL: `http://localhost/profile/view/1`
3. Send
4. Check Response tab

---

## Testing with Browser

Just visit in browser address bar:
```
http://localhost/profile/view/1
http://localhost/profile/email/1
http://localhost/profile/login/john/pass123
http://localhost/profile/full_profile/1
```

Responses will display as nicely formatted JSON.

---

## Verify It's Working

### Check 1: Module Loading
- Both vendor/user and vendor/profile should load
- Check dist.php has both modules
- No errors in log

### Check 2: API Registration
- vendor/user registers 3 APIs: get_user, get_user_email, authenticate
- vendor/profile registers 4 routes: view, email, login, full_profile

### Check 3: Dependency Order
- vendor/user loads **first**
- vendor/profile loads **after** (depends on vendor/user)

### Check 4: API Calls Work
- Routes respond with correct data
- User IDs match responses
- Credentials authenticate correctly

---

## Understanding the Flow

### Request 1: View User
```
1. Browser: GET /profile/view/1
   ↓
2. Razy Router: Match to profile.view.php
   ↓
3. Route Handler: Calls $this->api('vendor/user')->get_user(1)
   ↓
4. API Lookup: Find 'get_user' in vendor/user
   ↓
5. Execute: vendor/user/api/get_user.php
   ↓
6. Return: User object {'id': 1, 'name': 'John Doe', ...}
   ↓
7. Handler: Wrap in response object
   ↓
8. Browser: Receive JSON
```

### Request 2: Full Profile (Multiple APIs)
```
1. Browser: GET /profile/full_profile/1
   ↓
2. Route: profile.full_profile.php
   ↓
3. First API Call: $this->api('vendor/user')->get_user(1)
   ↓
4. Execute: user/api/get_user.php → {'id': 1, 'name': 'John Doe', ...}
   ↓
5. Second API Call: $this->api('vendor/user')->get_user_email(1)
   ↓
6. Execute: user/api/get_user_email.php → {'user_id': 1, 'email': 'john@example.com'}
   ↓
7. Combine Results: Merge user + email
   ↓
8. Browser: Receive combined JSON
```

---

## Success Criteria ✅

- [ ] `/profile/view/1` returns user object
- [ ] `/profile/email/1` returns email
- [ ] `/profile/login/john/pass123` authenticates
- [ ] `/profile/full_profile/1` returns combined profile
- [ ] Invalid user returns error message
- [ ] Wrong password returns error message
- [ ] All responses are valid JSON

---

## Next: Study the Code

After testing, review:
1. **User Module**: `sites/mysite/modules/vendor/user/`
2. **Profile Module**: `sites/mysite/modules/vendor/profile/`
3. **Configuration**: `sites/mysite/dist.php`

See [CROSS-MODULE-API-USAGE.md](../docs/guides/CROSS-MODULE-API-USAGE.md) for detailed code walkthrough.

---

## Troubleshooting

### Routes Not Found (404)
- Check dist.php includes both modules
- Verify URL format: `/profile/view/1`
- Check module loading is successful

### API Not Found Error
- Verify vendor/user registers APIs in controller/user.php
- Check API files exist in vendor/user/api/

### JSON Parse Error
- Check PHP version supports return type declarations
- Verify no PHP errors in response

---

**Ready?** Start with Test 1: `/profile/view/1` 🚀
