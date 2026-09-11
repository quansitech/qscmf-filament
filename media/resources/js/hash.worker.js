/**
 * cmf-media 大文件内容哈希 Worker：
 * Blob.slice 8MB 分片读取 + spark-md5 增量 API，内存占用恒定、不阻塞主线程。
 *
 * 主线程 postMessage({ file, sparkUrl, chunkSize? })，回报：
 *   { progress: 0..1 }  计算进度
 *   { hash }            完成
 *   { error }           失败
 */
self.onmessage = async function (event) {
    const { file, sparkUrl } = event.data;
    const chunkSize = event.data.chunkSize || 8 * 1024 * 1024;

    try {
        importScripts(sparkUrl);

        const spark = new self.SparkMD5.ArrayBuffer();
        const total = file.size;
        let offset = 0;

        while (offset < total) {
            const end = Math.min(offset + chunkSize, total);
            const buffer = await file.slice(offset, end).arrayBuffer();
            spark.append(buffer);
            offset = end;

            self.postMessage({ progress: total === 0 ? 1 : offset / total });
        }

        self.postMessage({ hash: spark.end() });
    } catch (e) {
        self.postMessage({ error: (e && e.message) || String(e) });
    }
};
