![#PEWS (Pew! Pew!)](assets/logo.png)
---------------------------------------------------
#### PHP Easy WebFinger Server

## Features
### Server
* Fully spec compliant webfinger server 
* Can accept json objects as requests
* Single or multiple domain use
### Server-Manager 
* Robust API for adding and removing new hosts and resource accounts
* API for altering resource data (currently add or remove alias, WIP)
* Authoritative and secure
* Easy user management
## Quick Start
### For single domain use
1. Copy `.well-known/webfinger` into the root of your webserver.
2. Add a new host and account resource file to `./well-known/webfinger/store` folling the example provided and according to Webfinger [specs](https://tools.ietf.org/html/rfc7033).
### For multiple domain use
1. Copy this repo as `PEWS` to your host server, and make it readable by your webserver. ex: `/var/www/PEWS`
2. Add a new host and account resource file to `./well-known/webfinger/store` folling the example provided and according to Webfinger [specs](https://tools.ietf.org/html/rfc7033).
3. Copy `PEWS/assets/pews.conf` to `/etc/apache2/conf-available/pews.conf` and make any changes necessary.
4. In the terminal issue the command `a2enconf pews` and follow instructions.
### Both:
The `PEWS` section of a PEWS resource file is not a part of the general Webfinger spec, and is therefore not sent out in a general inquiry. This section is used for PEWS-manager authentication and authorization.  
1. Set `class` to `user` or `admin`.  
2. Set a new password. If set by hand, this password will be hashed when first checked. Alternatively, a user can send a `POST` request with the following parameters to set a new password:   
	* `updatePass:user@domain.com`  
	* `pass:pewpewpassword`  
	* `newPass:YourFancyNEwPassword`  

If a user is `class:admin` then this user can alter the password of other users by adding `auth:admin-name@example.com` to the above post data, sending their own password as `pass:password`.
### TODO
1. Finish api for adding, removing, and editing resource `links`
2. Additional storage options? (sqlite, etc.)
3. Manager interface
4. Add in server-manager API examples (it's in the code... for now)
5. Strictly link-based resource capabilities (only account/user objects work for now)
### Tips
Dogecoin: DARhgg9q3HAWYZuN95DKnFonADrSWUimy3
