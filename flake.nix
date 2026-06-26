{
  description = "Valolink Plugin — WordPress development environment";

  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";
    flake-utils.url = "github:numtide/flake-utils";
  };

  outputs = { self, nixpkgs, flake-utils }:
    flake-utils.lib.eachDefaultSystem (system:
      let
        pkgs = nixpkgs.legacyPackages.${system};

        php = pkgs.php83.buildEnv {
          extensions = ({ enabled, all }: enabled ++ (with all; [
            curl
            dom
            exif
            fileinfo
            gd
            iconv
            intl
            mbstring
            mysqli
            openssl
            pdo
            pdo_mysql
            tokenizer
            xml
            xmlwriter
            zip
          ]));
          extraConfig = ''
            memory_limit = 256M
            upload_max_filesize = 64M
            post_max_size = 64M
          '';
        };

      in
      {
        devShells.default = pkgs.mkShell {
          name = "valolink-plugin";

          packages = [
            php
            pkgs.php83Packages.composer
            pkgs.wp-cli
            pkgs.nodejs   # occasionally useful for build tooling
            pkgs.watchexec
            pkgs.rsync
            pkgs.zip      # used by build.sh to produce the release zip
          ];

          shellHook = ''
            echo "valolink-plugin dev shell"
            echo "  php    $(php --version | head -1)"
            echo "  wp     $(wp --version 2>/dev/null || echo 'n/a — needs a WP install')"
            echo "  composer $(composer --version --no-ansi 2>/dev/null | head -1)"
          '';
        };
      });
}
