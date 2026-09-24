package buf

import (
	"bytes"
	"sync/atomic"
	"testing"
)

type outboundTestCounter struct{ v atomic.Int64 }

func (c *outboundTestCounter) Value() int64      { return c.v.Load() }
func (c *outboundTestCounter) Set(v int64) int64 { return c.v.Swap(v) }
func (c *outboundTestCounter) Add(v int64) int64 { return c.v.Add(v) }
func TestOutboundCounterIncludesBufferedAndDirectWrites(t *testing.T) {
	var sink bytes.Buffer
	counter := &outboundTestCounter{}
	writer := &BufferToBytesWriter{Writer: &sink, counter: counter}
	buffered := NewBufferedWriter(writer)
	if _, err := buffered.Write([]byte("header")); err != nil {
		t.Fatal(err)
	}
	if err := buffered.SetBuffered(false); err != nil {
		t.Fatal(err)
	}
	if got := counter.Value(); got != 6 {
		t.Fatalf("buffered header count=%d", got)
	}
	if _, err := buffered.Write([]byte("body")); err != nil {
		t.Fatal(err)
	}
	block := New()
	block.Write([]byte("more"))
	if err := writer.WriteMultiBuffer(MultiBuffer{block}); err != nil {
		t.Fatal(err)
	}
	if got := counter.Value(); got != 14 {
		t.Fatalf("writes missing or counted twice: %d", got)
	}
	if sink.String() != "headerbodymore" {
		t.Fatal(sink.String())
	}
}
