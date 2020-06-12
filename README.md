# TrustedLogin Example

An example of how to correctly and securely implement TrustedLogin into a distributed WordPress plugin.

To try running out of the box, set the following in `wp-config.php`:

```
define( 'TL_DOING_TESTS', true );
```

This will allow running with the `\ReplaceMe` namespace.

## If it's not showing anything, check the logs!

TrustedLogin is designed not to show anything when there's a failed configuration. This is by design.

Check your site's `/wp-uploads/trustedlogin-logs/` directory to see what the logs say (logging is enabled by default).
