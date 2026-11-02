/*
 * Synchronous wrapper around TigerBeetle's tb_client for PHP FFI.
 *
 * tb_client completes requests by calling back on its own I/O thread, and a
 * PHP closure cannot run there. This shim owns the callback: lb_request()
 * submits a packet and blocks on a condition variable until the callback has
 * copied the reply into shim-owned memory.
 *
 * If the caller times out, the request is marked abandoned instead of freed,
 * because tb_client still holds a pointer to its packet. The callback frees it
 * when the reply (or TB_PACKET_CLIENT_SHUTDOWN on close) eventually arrives.
 *
 * Build: bin/build-native.sh (links TigerBeetle's static libtb_client).
 */

#include <errno.h>
#include <pthread.h>
#include <stdlib.h>
#include <string.h>
#include <sys/time.h>

#include "tb_client.h"

#define LB_OK 0
#define LB_TIMEOUT -1
#define LB_CLIENT_INVALID -2
#define LB_OUT_OF_MEMORY -3
#define LB_REPLY_TOO_LARGE -4

typedef struct lb_client {
    tb_client_t tb; /* must stay at a stable address: heap-allocated */
} lb_client;

typedef struct lb_pending {
    pthread_mutex_t mu;
    pthread_cond_t cv;
    int done;
    int abandoned;
    tb_packet_t packet;
    uint8_t *input;
    uint8_t *reply;
    uint32_t reply_cap;
    uint32_t reply_len;
} lb_pending;

static void lb_pending_free(lb_pending *r) {
    pthread_mutex_destroy(&r->mu);
    pthread_cond_destroy(&r->cv);
    free(r->input);
    free(r->reply);
    free(r);
}

static void lb_on_complete(uintptr_t ctx, tb_packet_t *packet, uint64_t timestamp,
                           const uint8_t *result, uint32_t result_size) {
    (void)ctx;
    (void)timestamp;
    lb_pending *r = (lb_pending *)packet->user_data;

    pthread_mutex_lock(&r->mu);
    if (r->abandoned) {
        pthread_mutex_unlock(&r->mu);
        lb_pending_free(r);
        return;
    }
    r->reply_len = result_size;
    if (result != NULL && result_size > 0 && result_size <= r->reply_cap) {
        memcpy(r->reply, result, result_size);
    }
    r->done = 1;
    pthread_cond_signal(&r->cv);
    pthread_mutex_unlock(&r->mu);
}

lb_client *lb_client_open(const uint8_t cluster_id[16], const char *addresses,
                          uint32_t addresses_len, int *init_status_out) {
    lb_client *c = calloc(1, sizeof(lb_client));
    if (c == NULL) {
        *init_status_out = TB_INIT_OUT_OF_MEMORY;
        return NULL;
    }

    *init_status_out = tb_client_init(&c->tb, cluster_id, addresses, addresses_len, 0, lb_on_complete);
    if (*init_status_out != TB_INIT_SUCCESS) {
        free(c);
        return NULL;
    }

    return c;
}

void lb_client_close(lb_client *c) {
    if (c == NULL) {
        return;
    }
    /* Completes outstanding packets with TB_PACKET_CLIENT_SHUTDOWN first. */
    tb_client_deinit(&c->tb);
    free(c);
}

/*
 * Send one request and wait for its reply.
 *
 * Returns LB_OK with *packet_status_out set (TB_PACKET_*) and, when that is
 * TB_PACKET_OK, *reply_len_out bytes copied into reply. Other return values
 * are shim errors; on LB_TIMEOUT the request may still be applied by the
 * cluster.
 */
int lb_request(lb_client *c, uint8_t operation, const uint8_t *data, uint32_t data_size,
               uint8_t *reply, uint32_t reply_cap, uint32_t *reply_len_out,
               uint8_t *packet_status_out, uint32_t timeout_ms) {
    lb_pending *r = calloc(1, sizeof(lb_pending));
    if (r == NULL) {
        return LB_OUT_OF_MEMORY;
    }

    r->input = malloc(data_size > 0 ? data_size : 1);
    r->reply = malloc(reply_cap > 0 ? reply_cap : 1);
    if (r->input == NULL || r->reply == NULL) {
        free(r->input);
        free(r->reply);
        free(r);
        return LB_OUT_OF_MEMORY;
    }

    pthread_mutex_init(&r->mu, NULL);
    pthread_cond_init(&r->cv, NULL);
    memcpy(r->input, data, data_size);
    r->reply_cap = reply_cap;
    r->packet.user_data = r;
    r->packet.data = r->input;
    r->packet.data_size = data_size;
    r->packet.operation = operation;

    if (tb_client_submit(&c->tb, &r->packet) != TB_CLIENT_OK) {
        lb_pending_free(r);
        return LB_CLIENT_INVALID;
    }

    struct timeval now;
    gettimeofday(&now, NULL);
    struct timespec deadline;
    long long nsec = (long long)now.tv_usec * 1000 + (long long)(timeout_ms % 1000) * 1000000;
    deadline.tv_sec = now.tv_sec + timeout_ms / 1000 + nsec / 1000000000;
    deadline.tv_nsec = nsec % 1000000000;

    pthread_mutex_lock(&r->mu);
    int wait = 0;
    while (!r->done && wait != ETIMEDOUT) {
        wait = pthread_cond_timedwait(&r->cv, &r->mu, &deadline);
    }

    if (!r->done) {
        r->abandoned = 1;
        pthread_mutex_unlock(&r->mu);
        return LB_TIMEOUT;
    }
    pthread_mutex_unlock(&r->mu);

    int rc = LB_OK;
    *packet_status_out = r->packet.status;
    *reply_len_out = r->reply_len;
    if (r->packet.status == TB_PACKET_OK) {
        if (r->reply_len > reply_cap) {
            rc = LB_REPLY_TOO_LARGE;
        } else if (r->reply_len > 0) {
            memcpy(reply, r->reply, r->reply_len);
        }
    }

    lb_pending_free(r);
    return rc;
}
