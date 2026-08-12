/**
 * Uploads large files straight to Cloudflare R2 instead of routing them through
 * PHP, sidestepping post_max_size, upload_max_filesize and max_execution_time.
 *
 * Craft picks an uploader per filesystem type via Craft.createUploader(), so
 * registering here only affects R2 volumes. Everything else keeps using the
 * stock uploader.
 *
 * This extends Craft.Uploader rather than Craft.BaseUploader so the drop zone,
 * paste handling and file input plumbing all keep working — only the transport
 * is swapped out, and only for files big enough to be worth it.
 */
(function () {
  if (typeof Craft === 'undefined' || !Craft.Uploader || !Craft.registerUploaderClass) {
    return;
  }

  var MAX_CONCURRENT_PARTS = 3;

  // Reloading or navigating away mid-upload throws the upload away, and any
  // multipart parts already in the bucket go on costing storage until the
  // upload is aborted. Warn first, and abort what we can on the way out.
  var inFlight = {};
  var inFlightCount = 0;
  var unloadBound = false;

  function warnOnUnload(event) {
    if (inFlightCount < 1) {
      return undefined;
    }

    // Browsers show their own wording; the string is only a legacy formality.
    event.preventDefault();
    event.returnValue = '';

    return '';
  }

  function abortOnUnload(event) {
    // A persisted page is going into the back/forward cache and may well come
    // back with the upload still running — nothing to clean up yet.
    if ((event && event.persisted) || !navigator.sendBeacon) {
      return;
    }

    Object.keys(inFlight).forEach(function (token) {
      var body = new FormData();
      body.append('token', token);

      if (Craft.csrfTokenName) {
        body.append(Craft.csrfTokenName, Craft.csrfTokenValue);
      }

      // Fire-and-forget: a normal XHR would be killed with the page.
      navigator.sendBeacon(inFlight[token], body);
    });
  }

  function beginUpload() {
    inFlightCount++;

    if (!unloadBound) {
      window.addEventListener('beforeunload', warnOnUnload);
      window.addEventListener('pagehide', abortOnUnload);
      unloadBound = true;
    }
  }

  function endUpload(token) {
    inFlightCount = Math.max(0, inFlightCount - 1);

    if (token) {
      delete inFlight[token];
    }
  }

  var R2Uploader = Craft.Uploader.extend(
    {
      _directUploads: 0,
      _hud: null,

      /**
       * Craft.Uploader counts jQuery File Upload's active transfers, which never
       * sees ours. Consumers use this to decide when to hide the progress bar.
       */
      getInProgress: function () {
        return this.base() + this._directUploads;
      },

      onFileAdd: function (event, data) {
        var file = data.files && data.files[0];
        var threshold = this._settings().threshold;

        // Small files aren't worth three round trips — let Craft handle them.
        if (!file || file.size < threshold) {
          return this.base(event, data);
        }

        // A replace with no asset to replace isn't something we can target;
        // Craft's uploader knows what to do with it.
        if (this.settings.replace && !this.formData.assetId) {
          return this.base(event, data);
        }

        event.stopPropagation();

        if (this._validate(file)) {
          this._validFileCounter++;
          this._uploadDirect(file, data);
        }

        // Keep the batch counters in step with the base class, so a drop mixing
        // large and small files still flushes its rejection messages.
        if (++this._totalFileCounter === data.originalFiles.length) {
          this._totalFileCounter = 0;
          this._validFileCounter = 0;
          this.processErrorMessages();
        }

        return true;
      },

      /**
       * Mirrors the checks in Craft.Uploader.onFileAdd, minus the maxFileSize
       * test — clearing that limit is the entire point of uploading directly.
       */
      _validate: function (file) {
        if (this.allowedKinds) {
          if (!this._extensionList) {
            this._createExtensionList();
          }

          var match = file.name.match(/\.([a-z0-9_]+)$/i);
          var extension = match ? match[1].toLowerCase() : '';

          if ($.inArray(extension, this._extensionList) === -1) {
            this._rejectedFiles.type.push('“' + file.name + '”');
            return false;
          }
        }

        if (
          typeof this.settings.canAddMoreFiles === 'function' &&
          !this.settings.canAddMoreFiles(1)
        ) {
          this._rejectedFiles.limit.push('“' + file.name + '”');
          return false;
        }

        return true;
      },

      _settings: function () {
        return window.CloudflareR2PresignedUpload || {threshold: Infinity, actions: {}, fsType: null};
      },

      _uploadDirect: function (file, data) {
        var self = this;
        var token = null;
        var deferred = false;

        this._directUploads++;
        beginUpload();
        this.$element.trigger('fileuploadstart');
        this._openHud(file);
        this._reportProgress(0, file.size);

        this._post('start', {
          filename: file.name,
          size: file.size,
          mimeType: file.type || '',
        })
          .then(function (plan) {
            // The filesystem gets the final say. If direct uploads are off for
            // this bucket, hand the file back to Craft's normal uploader.
            if (plan.mode === 'traditional') {
              deferred = true;
              data.submit();
              return null;
            }

            token = plan.token;

            // Now abortable, so it can be cleaned up if the page goes away.
            inFlight[token] = Craft.getActionUrl(self._settings().actions.abort);

            if (plan.mode === 'multipart') {
              return self._uploadParts(file, plan).then(function (parts) {
                return self._post('complete', {token: token, parts: parts});
              });
            }

            return self
              ._put(plan.url, plan.headers, file, function (loaded) {
                self._reportProgress(loaded, file.size);
              })
              .then(function () {
                return self._post('complete', {token: token});
              });
          })
          .then(function (result) {
            if (deferred) {
              return;
            }

            self._reportProgress(file.size, file.size);
            self.$element.trigger('fileuploaddone', [{result: result, files: [file]}]);
          })
          .catch(function (error) {
            // Leaving a multipart upload dangling would keep billing storage.
            if (token) {
              self._post('abort', {token: token}).catch(function () {});
            }

            self._reportFailure(file, error);
          })
          .then(function () {
            self._directUploads--;
            endUpload(token);
            self._closeHud();

            // Craft's uploader owns the lifecycle now; it'll fire its own events.
            if (!deferred) {
              self.$element.trigger('fileuploadalways', [{files: [file]}]);
            }
          });
      },

      /**
       * Uploads every part, a few at a time, and collects their ETags.
       */
      _uploadParts: function (file, plan) {
        var self = this;
        var parts = plan.parts;
        var loaded = new Array(parts.length).fill(0);
        var results = new Array(parts.length);
        var next = 0;

        var report = function () {
          var total = loaded.reduce(function (sum, n) {
            return sum + n;
          }, 0);
          self._reportProgress(total, file.size);
        };

        var worker = function () {
          if (next >= parts.length) {
            return Promise.resolve();
          }

          var index = next++;
          var part = parts[index];
          var start = index * plan.partSize;
          var chunk = file.slice(start, Math.min(start + plan.partSize, file.size));

          return self
            ._put(part.url, null, chunk, function (bytes) {
              loaded[index] = bytes;
              report();
            })
            .then(function (xhr) {
              loaded[index] = chunk.size;
              report();

              // Readable only if the bucket's CORS policy exposes ETag. The
              // server asks the bucket directly, so this is just a shortcut —
              // not having it is fine.
              var etag = xhr.getResponseHeader('ETag');

              if (etag) {
                results[index] = {PartNumber: part.partNumber, ETag: etag};
              }

              return worker();
            });
        };

        var workers = [];

        for (var i = 0; i < Math.min(MAX_CONCURRENT_PARTS, parts.length); i++) {
          workers.push(worker());
        }

        return Promise.all(workers).then(function () {
          // Sparse if the bucket didn't expose ETag; the server falls back to
          // asking the bucket which parts it actually received.
          return results.filter(Boolean);
        });
      },

      /**
       * Sends a body straight to the bucket. Deliberately not Craft.sendActionRequest:
       * this is cross-origin and must not carry Craft's CSRF header or cookies.
       */
      _put: function (url, headers, body, onProgress) {
        return new Promise(function (resolve, reject) {
          var xhr = new XMLHttpRequest();
          xhr.open('PUT', url, true);

          if (headers) {
            Object.keys(headers).forEach(function (name) {
              xhr.setRequestHeader(name, headers[name]);
            });
          }

          xhr.upload.onprogress = function (event) {
            if (event.lengthComputable) {
              onProgress(event.loaded);
            }
          };

          xhr.onload = function () {
            if (xhr.status >= 200 && xhr.status < 300) {
              resolve(xhr);
            } else {
              reject(new Error(Craft.t('app', 'Upload failed with status {status}.', {status: xhr.status})));
            }
          };

          xhr.onerror = function () {
            reject(
              new Error(
                Craft.t('app', 'Couldn’t reach the storage bucket. Check its CORS configuration.')
              )
            );
          };

          xhr.onabort = function () {
            reject(new Error(Craft.t('app', 'The upload was cancelled.')));
          };

          xhr.send(body);
        });
      },

      _post: function (action, params) {
        var data = $.extend({}, this.formData, params);

        // formData carries Craft's CSRF token, which sendActionRequest adds itself.
        if (Craft.csrfTokenName) {
          delete data[Craft.csrfTokenName];
        }

        return Craft.sendActionRequest('POST', this._settings().actions[action], {
          data: data,
        }).then(function (response) {
          return response.data;
        });
      },

      _reportProgress: function (loaded, total) {
        this.$element.trigger('fileuploadprogressall', [{loaded: loaded, total: total}]);

        if (this._hud) {
          var percent = total ? Math.min(Math.round((loaded / total) * 100), 100) : 0;
          this._hud.bar.setProgressPercentage(percent, true);
          this._hud.$percent.text(percent + '%');
        }
      },

      /**
       * Puts up a progress bar, but only when whoever created this uploader
       * isn't already showing one.
       *
       * Craft's replace flow is the case that matters: it only wires up a
       * spinner, which told you nothing over the several minutes a large direct
       * upload can take.
       */
      _openHud: function (file) {
        if ((this.events && this.events.fileuploadprogressall) || this._hud) {
          return;
        }

        var $hud = $('<div class="r2-upload-progress"/>').appendTo(Garnish.$bod);
        var $head = $('<div class="r2-upload-progress__head"/>').appendTo($hud);

        $('<span class="r2-upload-progress__name"/>').text(file.name).appendTo($head);

        this._hud = {
          $el: $hud,
          $percent: $('<span class="r2-upload-progress__percent"/>').text('0%').appendTo($head),
          bar: new Craft.ProgressBar($hud),
        };

        this._hud.bar.showProgressBar();
      },

      _closeHud: function () {
        // Hold it open while anything else is still in flight.
        if (this._hud && this._directUploads < 1) {
          this._hud.$el.remove();
          this._hud = null;
        }
      },

      _reportFailure: function (file, error) {
        var message =
          (error &&
            error.response &&
            error.response.data &&
            (error.response.data.message || error.response.data.error)) ||
          (error && error.message) ||
          Craft.t('app', 'Upload failed for “{filename}”.', {filename: file.name});

        var payload = {message: message, filename: file.name};

        this.$element.trigger('fileuploadfail', [
          {
            files: [file],
            jqXHR: {responseJSON: payload},
            response: function () {
              return {jqXHR: {responseJSON: payload}};
            },
          },
        ]);
      },
    },
    {
      defaults: Craft.Uploader.defaults,
    }
  );

  Craft.R2Uploader = R2Uploader;

  var fsType = (window.CloudflareR2PresignedUpload || {}).fsType;

  if (fsType) {
    try {
      Craft.registerUploaderClass(fsType, R2Uploader);
    } catch (e) {
      // Already registered on this page load.
    }
  }
})();
