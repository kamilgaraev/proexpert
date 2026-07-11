# Geometry runtime third-party notices

- GNU LibreDWG 0.13.4 — GPL-3.0-or-later. The production image builds the unmodified source from the tagged release and invokes `dwgread` as a separate process. The exact Corresponding Source archive is redistributed inside the image at `/usr/share/source/libredwg-0.13.4.tar.xz`; its SHA-256 is pinned in `Dockerfile.prod`. License: https://www.gnu.org/licenses/gpl-3.0.html.
- ezdxf 1.4.4 — MIT. Source and license: https://github.com/mozman/ezdxf/tree/v1.4.4.
- pypdfium2 5.8.0 — Apache-2.0 OR BSD-3-Clause; bundled PDFium has its own BSD-style notices. Source and notices: https://github.com/pypdfium2-team/pypdfium2/tree/5.8.0.

The synthetic `simple-house.dxf` fixture is authored for МОСТ tests. `simple-house.dwg` is its generated representation produced by GNU LibreDWG 0.13.4 `dxf2dwg`; no third-party drawing content is included.
