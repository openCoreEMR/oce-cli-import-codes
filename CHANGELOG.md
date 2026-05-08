# Changelog

## [0.9.2](https://github.com/openCoreEMR/oce-cli-import-codes/compare/0.9.1...0.9.2) (2026-05-08)


### Bug Fixes

* **ci:** grant build-phar caller job contents:write permission ([#37](https://github.com/openCoreEMR/oce-cli-import-codes/issues/37)) ([b017d95](https://github.com/openCoreEMR/oce-cli-import-codes/commit/b017d95243e05f5e502aa76eaa72e2efd97f049e))


### Dependencies

* **deps-dev:** bump ergebnis/composer-normalize from 2.48.2 to 2.51.0 ([#30](https://github.com/openCoreEMR/oce-cli-import-codes/issues/30)) ([810fc2c](https://github.com/openCoreEMR/oce-cli-import-codes/commit/810fc2c72ea7e2f3d7490e65b383da9398230dd0))
* **deps-dev:** bump rector/rector from 2.3.1 to 2.4.2 ([#35](https://github.com/openCoreEMR/oce-cli-import-codes/issues/35)) ([adf1f20](https://github.com/openCoreEMR/oce-cli-import-codes/commit/adf1f20866428758099eadf0ad31bf48aab5f1db))
* **deps:** bump actions/cache from 4 to 5 ([#34](https://github.com/openCoreEMR/oce-cli-import-codes/issues/34)) ([054e7e8](https://github.com/openCoreEMR/oce-cli-import-codes/commit/054e7e838669ff960616b66afb4d883f8d2aa106))
* **deps:** bump actions/checkout from 4 to 6 ([#29](https://github.com/openCoreEMR/oce-cli-import-codes/issues/29)) ([188ee12](https://github.com/openCoreEMR/oce-cli-import-codes/commit/188ee1212ca53ded29bac24317654448351793b0))
* **deps:** bump actions/upload-artifact from 4 to 7 ([#31](https://github.com/openCoreEMR/oce-cli-import-codes/issues/31)) ([0155d8d](https://github.com/openCoreEMR/oce-cli-import-codes/commit/0155d8df293b80dc0d486e417d2d9f5e24d3a1f9))
* **deps:** bump rhysd/actionlint from 1.7.10 to 1.7.12 ([#36](https://github.com/openCoreEMR/oce-cli-import-codes/issues/36)) ([2083e04](https://github.com/openCoreEMR/oce-cli-import-codes/commit/2083e04027cd6c57abac7697b13a34c6e46d739a))
* **deps:** bump softprops/action-gh-release from 2 to 3 ([#33](https://github.com/openCoreEMR/oce-cli-import-codes/issues/33)) ([f0f5301](https://github.com/openCoreEMR/oce-cli-import-codes/commit/f0f53018ab4316b93d876f83487aa512675ffdde))

## [0.9.1](https://github.com/openCoreEMR/oce-cli-import-codes/compare/0.9.0...0.9.1) (2026-01-19)


### Bug Fixes

* **ImportCodesCommand:** only check supported_external_dataloads for ICD types ([#26](https://github.com/openCoreEMR/oce-cli-import-codes/issues/26)) ([41515b4](https://github.com/openCoreEMR/oce-cli-import-codes/commit/41515b443735bc712a2750ab84f7665fd897bbe3))

## [0.9.0](https://github.com/openCoreEMR/oce-cli-import-codes/compare/0.1.1...0.9.0) (2026-01-18)


### Bug Fixes

* **ci:** chain build-phar workflow from release-please ([#21](https://github.com/openCoreEMR/oce-cli-import-codes/issues/21)) ([69b514f](https://github.com/openCoreEMR/oce-cli-import-codes/commit/69b514fc86f50fb1878437f0ae619a0dd454cbd4)), closes [#20](https://github.com/openCoreEMR/oce-cli-import-codes/issues/20)
* **CodeImporter:** identify lock and lock holder ([#5](https://github.com/openCoreEMR/oce-cli-import-codes/issues/5)) ([d8d8bfb](https://github.com/openCoreEMR/oce-cli-import-codes/commit/d8d8bfb7211a2ed88b256add91b255aab87dd5ed))


### Miscellaneous Chores

* **release:** 0.9.0 ([78f4af1](https://github.com/openCoreEMR/oce-cli-import-codes/commit/78f4af199b827b90e854bc9f0c031a57d90a0a64))


### Code Refactoring

* add GlobalsAccessor abstraction for OpenEMR globals access ([#23](https://github.com/openCoreEMR/oce-cli-import-codes/issues/23)) ([954ac68](https://github.com/openCoreEMR/oce-cli-import-codes/commit/954ac68eac181959765333af77cbf47812323f18)), closes [#19](https://github.com/openCoreEMR/oce-cli-import-codes/issues/19)
* catch Throwable instead of Exception ([#25](https://github.com/openCoreEMR/oce-cli-import-codes/issues/25)) ([917c70d](https://github.com/openCoreEMR/oce-cli-import-codes/commit/917c70d7048fc54a7ea509235b340aab7c2324bb)), closes [#24](https://github.com/openCoreEMR/oce-cli-import-codes/issues/24)

## [0.1.1](https://github.com/openCoreEMR/oce-cli-import-codes/compare/0.1.0...0.1.1) (2026-01-16)


### Features

* cli and phar builder ([c675073](https://github.com/openCoreEMR/oce-cli-import-codes/commit/c6750736132943dacfa26f4c38ef9d86f9074ad7))
* **command:** concurrency control ([7ee4279](https://github.com/openCoreEMR/oce-cli-import-codes/commit/7ee4279de8fedceede6195f4cadfc1ee98800ffc))
* **command:** idempotence by default ([a6a6134](https://github.com/openCoreEMR/oce-cli-import-codes/commit/a6a6134187ea3516c9d77387187b9ca9ecb4b218))
* **command:** output as json for logging ([73b0691](https://github.com/openCoreEMR/oce-cli-import-codes/commit/73b0691c41ae4a84ad80fa156e7ee93f99705a1d))
* **concurrency:** recheck if vocab is loaded after waiting for lock ([481fda1](https://github.com/openCoreEMR/oce-cli-import-codes/commit/481fda1703dfcdd58eca97117d4a16803884b525))
* **import:** add --allow-unsupported flag and improve staging cleanup ([#18](https://github.com/openCoreEMR/oce-cli-import-codes/issues/18)) ([b710414](https://github.com/openCoreEMR/oce-cli-import-codes/commit/b7104143bf8e8c96ee0e96787d046d6d8916784f))
* remove progress bars ([81875ca](https://github.com/openCoreEMR/oce-cli-import-codes/commit/81875cab1469781fd23c7e9d5ba02f260b667754))
* **tooling:** add development environment and code quality tools ([#9](https://github.com/openCoreEMR/oce-cli-import-codes/issues/9)) ([7f495ff](https://github.com/openCoreEMR/oce-cli-import-codes/commit/7f495fff9127c41a02d92c714da8ea6bd8d52ea5))


### Bug Fixes

* **command:** non-interactive ([788d04d](https://github.com/openCoreEMR/oce-cli-import-codes/commit/788d04d93f3302b64d8410798605126a8cd36210))
* improve script flow and snomed reg ([cd52ede](https://github.com/openCoreEMR/oce-cli-import-codes/commit/cd52ede19cda0f1f33f7bd6efa01a40bf6ab9749))
* **oce-import-codes:** fix the autoload path ([2ac5f6e](https://github.com/openCoreEMR/oce-cli-import-codes/commit/2ac5f6ec8345a0783d199f7ab613f112451c8c66))
* updates after testing ([0f22537](https://github.com/openCoreEMR/oce-cli-import-codes/commit/0f225374c7e6bc7033df0b6f915e82ef968f5f2b))

## Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
