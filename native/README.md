# Native client

- `ledgerbeetle_tb.c` is this package's shim. It turns tb_client's callback API into a blocking call that PHP FFI can use.
- `tb_client.h` is TigerBeetle's generated C header for **0.17.9**, copied unchanged from [tigerbeetle-go `native/tb_client.h` at v0.17.9](https://github.com/tigerbeetle/tigerbeetle-go/blob/v0.17.9/native/tb_client.h) (git blob `bb4554e6f2ece3693cfab0c34d55acf79960cac6`). TigerBeetle is licensed under Apache-2.0.

`bin/build-native.sh` links the shim against TigerBeetle's prebuilt `libtb_client` shared library from the official [`tigerbeetle` PyPI wheel](https://pypi.org/project/tigerbeetle/), after checking the wheel against the SHA-256 that PyPI publishes. When you upgrade TigerBeetle, replace `tb_client.h` with the header for the same version.
