# Tor Delivery — static site + one PHP form handler (submit-report.php)
#
# This is a flat set of HTML/CSS/JS/PHP files, no build step. The only thing
# that needs to actually run server-side is submit-report.php, which handles
# the whistleblower, account-deletion and contact forms and relies on PHP's
# mail() function. Everything else is served as-is.

FROM php:8.5.12-apache

# Apache's default docroot is /var/www/html; copy the whole repo there.
WORKDIR /var/www/html

# sendmail is required for PHP's mail() to actually hand off messages.
# Without a configured MTA, submit-report.php will run but every send will
# fail — see the README note at the bottom of this file for what Dokploy
# needs to set up alongside this container.
RUN apt-get update \
    && apt-get install -y --no-install-recommends msmtp msmtp-mta \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/

# Drop files that have no business being served: local dev tooling,
# repo/CI metadata, and anything .deployignore already excludes from the
# old SCP pipeline (kept here for parity — same list, same intent).
RUN rm -rf \
      /var/www/html/.git \
      /var/www/html/.github \
      /var/www/html/.gitignore \
      /var/www/html/.deployignore \
      /var/www/html/node_modules \
      /var/www/html/package.json \
      /var/www/html/package-lock.json \
      /var/www/html/eslint.config.mjs \
      /var/www/html/README.md \
      /var/www/html/Dockerfile

# Clean URLs (e.g. /contact instead of /contact.html) are handled by the
# .htaccess at the docroot via mod_rewrite. The default php:8.2-apache vhost
# has AllowOverride None for /var/www/, which makes Apache silently ignore
# .htaccess entirely — this explicitly permits it for our docroot only.
RUN a2enmod rewrite \
    && { \
      echo '<Directory /var/www/html>'; \
      echo '  AllowOverride All'; \
      echo '</Directory>'; \
    } > /etc/apache2/conf-available/htaccess-allow.conf \
    && a2enconf htaccess-allow

EXPOSE 80

# ---------------------------------------------------------------------------
# NOTE FOR WHOEVER CONFIGURES THIS IN DOKPLOY
#
# submit-report.php calls PHP's mail(), which needs a working outbound mail
# transport inside the container. This image installs msmtp as the sendmail
# binary PHP expects, but msmtp itself still needs to be told which SMTP
# relay to use — that's environment/secret configuration, not something
# baked into this Dockerfile:
#
#   - Set an SMTP relay (e.g. the VPS's own Postfix, or a transactional
#     email provider like SendGrid/Mailgun/SES) via environment variables,
#     and point /etc/msmtprc (or php.ini's sendmail_path) at it.
#   - Until that's configured, every form submission on this site
#     (whistleblower reports, deletion requests, contact enquiries) will
#     fail at the mail() call and the visitor will see a real error —
#     which is the correct failure mode, not a silent one, but it means
#     the forms are non-functional until mail delivery is wired up.
#
# This mirrors the same requirement the old VPS/SCP deploy had — PHP mail()
# was never guaranteed to work there either without server-side mail
# configuration; moving to Dokploy doesn't remove that dependency, it just
# moves where it needs to be configured.
# ---------------------------------------------------------------------------
