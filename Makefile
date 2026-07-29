app_name=moodle_talk_bridge
build_dir=$(CURDIR)/build
sign_dir=$(build_dir)/sign
cert_dir=$(HOME)/.nextcloud/certificates

# Everything that must NOT ship in the App Store tarball.
exclude=--exclude='.git' --exclude='.github' --exclude='.gitignore' \
        --exclude='build' --exclude='Makefile' \
        --exclude='tests' --exclude='phpunit.xml' \
        --exclude='.phpunit.result.cache' \
        --exclude='composer.json' --exclude='composer.lock' --exclude='vendor' \
		--exclude='.claude' --exclude='RELEASING.md'

.PHONY: appstore clean

# Builds the signed tarball for https://apps.nextcloud.com
#
# Requires:
#   $(cert_dir)/$(app_name).key  - private key (keep secret)
#   $(cert_dir)/$(app_name).crt  - certificate signed by Nextcloud
#   OCC=/path/to/occ             - a Nextcloud server's occ, used to sign
#
# Signing must happen BEFORE packaging: occ writes signature.json into the app
# directory, and any change made after signing invalidates the signature.
#
# Staging into a directory explicitly named $(app_name) keeps the tarball's
# top-level folder correct whatever the checkout directory is called; the App
# Store rejects an archive whose root folder is not the app id.
appstore: clean
	mkdir -p $(sign_dir)/$(app_name)
	tar $(exclude) -cf - -C $(CURDIR) . | tar -xf - -C $(sign_dir)/$(app_name)
ifdef OCC
	php $(OCC) integrity:sign-app \
		--privateKey=$(cert_dir)/$(app_name).key \
		--certificate=$(cert_dir)/$(app_name).crt \
		--path=$(sign_dir)/$(app_name)
else
	@echo ">> OCC not set - skipping code signing."
	@echo ">> The App Store REQUIRES a signed app. Re-run as:"
	@echo ">>   make appstore OCC=/var/www/html/occ"
endif
	tar -czf $(build_dir)/$(app_name).tar.gz -C $(sign_dir) $(app_name)
	@echo "Built $(build_dir)/$(app_name).tar.gz"
	@echo "Upload signature for apps.nextcloud.com:"
	@openssl dgst -sha512 -sign $(cert_dir)/$(app_name).key \
		$(build_dir)/$(app_name).tar.gz | openssl base64 -A || \
		echo "(private key not found at $(cert_dir)/$(app_name).key)"

clean:
	rm -rf $(build_dir)
