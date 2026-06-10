# Mock Entware environment for testing install.sh and update.sh.
# This is NOT a production image — it just provides BusyBox + PHP + lighttpd
# under /opt so that install.sh can run without a real Keenetic router.

FROM alpine:3.20

RUN apk add --no-cache \
        bash busybox-suid \
        curl \
        ca-certificates \
        php83 php83-cgi php83-curl php83-session php83-openssl \
        lighttpd lighttpd-mod_auth \
        coreutils findutils grep sed \
        jq \
        bind-tools \
        ipset iptables \
        qrencode \
        cronie \
    && mkdir -p /opt/bin /opt/sbin /opt/etc/init.d /opt/etc/cron.d \
                /opt/share/www/xray /opt/var/log/xray /opt/var/run /opt/tmp

# Symlink Alpine binaries into /opt so the scripts find them in Entware paths
RUN for b in php php-cgi curl wget sha256sum awk grep sed tar ip ipset iptables qrencode dig; do \
        target="$(which "$b" 2>/dev/null || true)"; \
        [ -n "$target" ] && ln -sf "$target" "/opt/bin/$b" || true; \
    done && \
    ln -sf "$(which php83)" /opt/bin/php && \
    ln -sf "$(which php-cgi83 2>/dev/null || which php-cgi)" /opt/bin/php-cgi

# Fake opkg & ndmc & xray binaries (just enough to satisfy install.sh + smoke tests)
COPY fake-opkg /opt/bin/opkg
COPY fake-ndmc /opt/bin/ndmc
COPY fake-xray /opt/sbin/xray
RUN chmod +x /opt/bin/opkg /opt/bin/ndmc /opt/sbin/xray

# Minimal init.d skeletons
COPY fake-init.d/ /opt/etc/init.d/
RUN chmod +x /opt/etc/init.d/S* 2>/dev/null || true

# Lighttpd base config (rest comes from install.sh)
COPY lighttpd.conf /opt/etc/lighttpd/lighttpd.conf
COPY mime.conf /opt/etc/lighttpd/mime.conf
RUN mkdir -p /opt/etc/lighttpd/conf.d

EXPOSE 91

ENV PATH="/opt/sbin:/opt/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
WORKDIR /opt

CMD ["sh", "-c", "lighttpd -D -f /opt/etc/lighttpd/lighttpd.conf"]
