# PHP Deployer

A lightweight PHP based deploy framework, that works on safe mode servers

## Requirements
- PHP >= 5.3.9
- [Composer](https://getcomposer.org)

## Install

1. Check out the repository
2. Run a composer update
3. Upload `.htaccess`, `index.php` and the `vendor` folder to the server
4. Create `services` folder and configure services (see Configuration)
5. Add deploy script to your repo (see Deploy)

## Configuration

You can configure a service by adding a `deploy.yml` to `services/<SERVICE_NAME>/`.
This file should look like this:

```yaml
<SERVICE_NAME>:
    secret: <DEPLOYMENT_SECRET>
    current_path: <PATH_FOR_THE_ACTIVE_SERVICE>
    old_path: <PATH_FOR_THE_ROLLBACK_BACKUP>
    extra_files:
        - <LIST_OF_EXTRA_FILES>
        - ...
```

- `<SERVICE_NAME>`: the name of the service
- `<DEPLOYMENT_SECRET>`: a secret phrase you have to send with every deploy or rollback
- `<PATH_FOR_THE_ACTIVE_SERVICE>`: the root folder of the service
- `<PATH_FOR_THE_ROLLBACK_BACKUP>`: is the folder where the previous deployed version
will be stored on deploy. You can rollback from here (see Rollback)
- `<LIST_OF_EXTRA_FILES>`: these files should also be added to the `services/<SERVICE_NAME>/`
folder and they will be copied to the root of the service. It's an ideal way to add config
files, if you want to store them online.

## Deploy

You have to create a deploy script which assembles the deploy artifact. This will be a
tarball archive (`.tar.gz`) which should only contain the files and folders you want to publish.
You can add the environment specific configuration files here (or later, see Extra files). A basic
deploy script (on a Unix-like system) should look something like this:

```sh
#!/bin/sh
ARTIFACT=`pwd`artifact.tar.gz
tar -czf "$ARTIFACT" <LIST_OF_INCLUDED_FILES>
curl -F "artifact=@$ARTIFACT" -F "secret=<DEPLOYMENT_SECRET>" <URL_OF_DEPLOYER>/deploy/<SERVICE_NAME>
```

- `<LIST_OF_INCLUDED_FILES>`: files and folders you want to publish
- `<DOMAIN_OF_DEPLOYER>`: the url to the deployer's root

## Rollback

After the initial deploy every new one will back up the previous build. If anything goes wrong
you can easily roll back with a rollback script, which looks like this:

```sh
#!/bin/sh
curl -F "secret=<DEPLOYMENT_SECRET>" <URL_OF_DEPLOYER>/rollback/<SERVICE_NAME>
```
