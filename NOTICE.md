# Third-party components

## DocAligner corner model
`models/docaligner-lcnet100.onnx` is the LCNet-100 heatmap model from
[DocAligner](https://github.com/DocsaidLab/DocAligner) by DocsaidLab,
licensed under the Apache License 2.0 (see `models/LICENSE-DocAligner.txt`).
Used unmodified.

## ONNX Runtime Web
`vendor/ort/ort-wasm-simd-threaded.wasm` is from
[onnxruntime-web](https://github.com/microsoft/onnxruntime) 1.20.1 by Microsoft,
MIT License. The JavaScript loader is fetched from jsDelivr at the same version.

## OpenCV.js (fallback detector)
Loaded from jsDelivr (`@techstark/opencv-js` 4.10.0), Apache License 2.0.

## jsPDF
Loaded from cdnjs (jsPDF 2.5.1), MIT License.
